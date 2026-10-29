# ADR-0003: Authentication with Laravel Sanctum and multi-method login

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Foundational for email/username and Sanctum; deferrable for phone OTP and social login (the schema hooks are foundational)

## Context

- **[Fact]** Sanctum is **not installed**.
- **[Fact]** `laravel/sanctum` v4.3.3 (2026-06-23) declares `illuminate/* ^11|^12|^13` and `php ^8.2`, so it is compatible with the installed Laravel 13.39.0 and PHP 8.5.12.
- **[Fact]** `config/auth.php` has only the `web` session guard. `SESSION_DRIVER=database`, `SESSION_DOMAIN=null`, `same_site=lax`, and `SESSION_SECURE_COOKIE` is unset.
- **[Fact]** `laravel/socialite` v5.31.0 supports `illuminate ^13`.
- **[Fact]** The runtime has `curl`, `intl`, and `sodium`, and **disables `proc_open`, `exec`, `putenv`, `mail`, and the others listed in `php/security.ini`**. Socialite and any SMS/HTTP SDK must use cURL/Guzzle; `mail()` is unavailable, so email must use an SMTP or API mailer.
- **[Fact]** No React or mobile application code exists in this repository, so client deployment topology (domains) is unknown.

## Problem statement

Which authentication mechanisms should each client use, how do multiple login methods map to one account without duplication or unsafe merging, and how are sessions and tokens issued and revoked?

## Decision

### 1. Sanctum as the single authentication layer

Use **Sanctum 4.x** in both of its modes, with one `users` provider:

| Client | Mechanism | Notes |
|---|---|---|
| **React admin (web)** | **Stateful SPA cookie session** (`EnsureFrontendRequestsAreStateful`) | Requires the SPA and the API on the **same registrable domain** (e.g. `admin.example.com` + `api.example.com`). It also requires `SANCTUM_STATEFUL_DOMAINS=admin.example.com`, `SESSION_DOMAIN=.example.com`, `SESSION_SECURE_COOKIE=true`, `same_site=lax`, CORS `supports_credentials=true` with an exact origin allow-list, and CSRF through `GET /sanctum/csrf-cookie` plus the `X-XSRF-TOKEN` header. Logout invalidates the session and regenerates the token. Session lifetime is 120 min idle (current value), plus an absolute maximum for admin sessions enforced by middleware (Q3). |
| **Admin mobile** | **Personal access token**, one per device | Created with abilities `['client:admin']` and a name like `admin-mobile:<device-id>`. Expires at 30 days (Q2). |
| **Public mobile** | Personal access token, one per device | Abilities `['client:public']`. Expires at 90 days (Q2). |
| **Public website** | The same SPA cookie mode if it is a JS app on the same domain; otherwise the server-rendered `web` guard | Never gets `client:admin`. |

- **Fallback, if the admin SPA cannot share a registrable domain with the API:** use short-lived tokens kept **in memory only** (not in `localStorage`), re-issued from an `HttpOnly` refresh cookie scoped to the API domain. This is more complex, so the recommended course is to fix the domain topology (Q1).
- **Client binding.** Abilities are used **only** to bind a token to a client type, never for business authorization ([ADR-0004](0004-authorization-with-spatie-permission.md)). `EnsureAdminClient` accepts a stateful request from an allowed admin origin, or a token with `client:admin`. Admin-mobile tokens are issued only through `POST /api/v1/admin/auth/token`, which requires `account_type=admin`. Public tokens come from `POST /api/v1/auth/token`.
- **Token expiry.**
  - **[Fact]** Sanctum supports per-token `expires_at` (`createToken($name, $abilities, $expiresAt)`) and a global `sanctum.expiration`. It does **not** provide refresh tokens or rotation.
  - **[Recommendation]** Use per-token expiry, plus `last_used_at`-based idle revocation by a scheduled job.
  - **[Recommendation]** Clients log in again on expiry. Refresh-token rotation is **deferred** (it is optional and would need a custom table).
  - `sanctum:prune-expired` runs on the scheduler. **[Fact]** There is no scheduler or queue-worker container in `docker-compose.*.yml` today, so one must be added in implementation (see the roadmap).
- **Device management:**
  - `GET /api/v1/auth/devices` lists the user's own tokens (name, `last_used_at`, created date).
  - `DELETE /api/v1/auth/devices/{id}` logs out one device.
  - `DELETE /api/v1/auth/devices` logs out all devices.
  - `POST /api/v1/auth/logout` logs out the current device.
  - Admins with `users.revoke-sessions` can revoke another user's tokens and sessions.
  - Revocation on ban/deactivation is automatic ([ADR-0002](0002-account-classification-and-admin-access.md)).
- **Device storage guidance for the mobile teams:** iOS Keychain (`kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly`) and Android Keystore-backed EncryptedSharedPreferences/DataStore. Never put tokens in logs, analytics, or crash reports.

### 2. Login identifiers

`POST /api/v1/auth/login` accepts `{ identifier, password }`. The identifier type is determined **deterministically** by format, so ambiguity cannot arise:

| Rule | Type |
|---|---|
| Contains `@` | email |
| Starts with `+` or consists only of digits | phone (rejected for password login: phone is OTP-only, §3) |
| Otherwise | username |

Format rules that keep the classes disjoint:

- **Usernames:** `^[a-z0-9](?:[a-z0-9._]{1,28}[a-z0-9])$` (3–30 characters), at least one letter, never all digits, no `@`.
- Usernames are **stored lowercase** and compared lowercase.
- A reserved-name list (`admin`, `support`, `api`, `root`, `system`, the brand names, and so on) is blocked.
- **Emails:** trimmed and lowercased on write. Uniqueness is case-insensitive.
  - **[Fact]** The MySQL default collation (`utf8mb4_0900_ai_ci`) is already case-insensitive.
  - **[Fact]** SQLite, used by `phpunit.xml`, is case-sensitive by default. This is a test-fidelity gap ([ADR-0011](0011-dependencies-testing-and-ci.md)).
- **Username changes:** allowed, at most once per 30 days. The old username is held for 30 days so nobody else can take it.
- **Uniform failure:** unknown identifier, wrong password, and wrong identifier type all return the same `INVALID_CREDENTIALS` response with equal-cost hashing, to prevent user enumeration.
- **Password reset:** by email, using Laravel's built-in broker and the existing `password_reset_tokens` table. Reset tokens expire after 60 minutes. A successful reset **revokes all tokens and sessions**.
- **Email verification:** Laravel's `MustVerifyEmail` with signed URLs. Whether an unverified account may log in is product policy (Q4); the default is to let it log in but block content creation.

### 3. Phone authentication (deferrable phase; schema designed now)

- `users.phone`: E.164 format, `VARCHAR(16)`, unique, nullable. It holds **only a verified number** and is set only after OTP verification. A pending number lives in the challenge record, so nobody can squat a phone number by entering it unverified.
- `users.phone_verified_at`.
- `otp_challenges` table (Identity):
  - `id` (ULID), `purpose` (`login|verify_phone|reset`), `channel` (`sms|whatsapp`), `destination` (E.164), `code_hash` (HMAC-SHA256 with the app key; never plaintext), `user_id` (nullable)
  - `attempts`, `max_attempts` (5), `expires_at` (+5 min), `consumed_at`, `ip`, `created_at`
- Limits:
  - Resend no more than once per 60 s
  - At most 5 sends per number per hour and 10 per IP per hour
  - Per-country allow-list to control SMS cost and toll fraud
  - CAPTCHA on send ([ADR-0009](0009-audit-logging-and-security-controls.md))
- Phone login never creates an account implicitly unless sign-up-by-phone is enabled (Q5).
- **Provider:** none is chosen. It goes behind the `Identity\Contracts\OtpSender` interface (Twilio Verify, Vonage, Unifonic, or Taqnyat as candidates; Q6). Validation uses `propaganistas/laravel-phone` 6.1.0 ([Fact] compatible with `illuminate ^13`), added in the phone phase only.

### 4. Social authentication (deferrable phase; schema designed now)

- **Package:** `laravel/socialite` 5.x for Google and Facebook. Apple needs the community `socialiteproviders/apple` package, which must be evaluated in that phase.
- **Web flow:** the standard redirect/callback with Socialite's `state` parameter (CSRF) and exact redirect-URI registration.
- **Mobile flow:** the native SDK gets a provider token or ID token. The backend **verifies it server-side** (signature, `aud` = our client ID, `iss`, `exp`, and the nonce for Apple) before trusting any claim. Client-supplied profile data is never trusted.
- `user_identities` table:
  - `id`, `user_id` (FK, cascade), `provider`, `provider_user_id`
  - `provider_email` (nullable), `provider_email_verified` (bool), `last_used_at`, timestamps
  - `UNIQUE(provider, provider_user_id)`
- **Linking rules** (no unsafe merging):
  1. A known `(provider, provider_user_id)` logs into the linked user, after status checks.
  2. An unknown identity whose verified provider email matches an existing account is **not auto-linked**. The response is `ACCOUNT_LINK_REQUIRED`; the user logs in with the existing method and links from settings (`POST /api/v1/auth/identities/{provider}`).
  3. An unknown identity with no match creates a **new `user`-type account**, if social sign-up is enabled.
- Unlinking is allowed only if another login method remains (a password, a verified phone, or another identity).
- Social login never sets or changes `account_type`.

### 5. One guard, and when more would be justified

- **Decision:** a **single authentication model**. There is one `users` provider, the `web` session guard (used by Sanctum's stateful SPA mode), and Sanctum's token resolution, which resolves the same provider.
- **[Fact]** The brief forbids adding guards only because there are several account categories.
- Admin, Writer, and Normal User are separated by `account_type` ([ADR-0002](0002-account-classification-and-admin-access.md)), by Workspaces ([ADR-0012](0012-administrative-workspaces.md)), and by permissions. None of them is a guard.
- **Separate guards would be justified only if one of these is true:**
  - A different **identity store** is involved: for example, machine-to-machine API clients that aren't `User` rows, which would need their own provider and credentials (such as client keys or a Passport client-credentials grant).
  - **Regulatory isolation** requires admin identities in a separate store or database.
  - A **different credential lifecycle** is needed that can't coexist on one model, such as hardware-key-only admin login enforced at the guard level.

  None of these applies today.
- A side effect worth noting: a single guard also avoids the main Spatie pitfall, where permissions are created under one `guard_name` and checked under another ([ADR-0004](0004-authorization-with-spatie-permission.md) §1).

### 6. Separation of responsibilities

- Sanctum authenticates.
- `account_type` and status draw the boundary ([ADR-0002](0002-account-classification-and-admin-access.md)).
- Workspace access permissions decide which admin areas an account may enter ([ADR-0012](0012-administrative-workspaces.md)).
- Spatie authorizes operations ([ADR-0004](0004-authorization-with-spatie-permission.md)).
- Category policies scope records ([ADR-0005](0005-category-level-authorization.md)).
- The locale changes only presentation ([ADR-0007](0007-arabic-english-localization.md)).

## Alternatives considered

| Option | Verdict |
|---|---|
| **Laravel Passport (OAuth2 server)** | Gives refresh tokens and scopes, but is heavyweight. OAuth2 flows aren't needed: there are no third-party clients and all clients are first-party. Rejected; it stays the fallback if third-party API access is ever required. |
| **JWT packages** | Stateless tokens are hard to revoke immediately, which conflicts with the instant revocation on ban. Rejected. |
| **Tokens for the React admin** instead of a cookie session | Exposes bearer tokens to XSS when stored in JS-readable storage. The cookie session is stronger when the domains allow it. Kept only as the fallback above. |
| **Laravel Fortify** (headless auth backend) | Gives reset, verification, and 2FA scaffolding. It is optional: phase 1 can use the built-in password broker plus thin actions. Evaluate when 2FA is requested (Q7). |

## Reasons and trade-offs

Sanctum is first-party and compatible, and one package covers both cookie and token modes. Its main limitation (no rotation or refresh tokens) is handled by per-token expiry and re-login, a deliberate trade of convenience for simplicity.

## Database implications

- **New tables:** `personal_access_tokens` (Sanctum), `otp_challenges` and `user_identities` (in their phases).
- **New `users` columns:** `username` (unique), `phone` (unique, nullable), `phone_verified_at`.
- **Reused:** the `sessions` and `password_reset_tokens` tables.

## Security implications

- Enumeration-resistant responses.
- Rate limits on every auth endpoint ([ADR-0009](0009-audit-logging-and-security-controls.md)).
- OTP codes are hashed.
- Tokens are stored hashed by Sanctum (SHA-256); the plaintext is shown once.
- CSRF for the SPA.
- Strict CORS: no wildcard with credentials.
- Session ID regenerated at login.
- Password hashing with bcrypt at cost 12 (current `BCRYPT_ROUNDS=12`); evaluate argon2id later.
- Admin accounts are strong candidates for mandatory 2FA (TOTP) in a later phase (Q7).

## Performance implications

- One indexed token lookup per token request; sessions are database-backed.
- With high traffic, move sessions and cache to Redis. **[Fact]** No Redis service exists today.

## Migration implications

- Publish the Sanctum migration into the Identity module.
- Add the `users` columns: `username` must be backfilled for existing rows (there are none in production yet) before the `NOT NULL` + unique constraint is applied.

## Testing requirements

- Login by email and by username, with case variants.
- Ambiguity rules: an all-digit username is rejected, and an `@` in a username is rejected.
- Identical responses for unknown and wrong-password logins.
- SPA flow: csrf-cookie, then login, then `/me`, then logout.
- Token expiry is honored.
- Device logout (one device and all devices).
- A public-client token on `/admin` gets 403.
- Revocation on password reset and on ban.
- OTP expiry, attempt limits, resend throttle, and hashed storage.
- Social: an existing identity logs in; an email match without a link gets `ACCOUNT_LINK_REQUIRED`; an invalid ID token is rejected.

## Consequences

The admin SPA's hosting topology is now an architectural requirement (Q1). Mobile apps must handle 401 by re-authenticating.

## Open questions

1. **Q1.** What are the production domains for the API, the admin SPA, and the website? *Recommended default: the same registrable domain.*
2. **Q2.** Token lifetimes for admin and public mobile? *Recommended default: 30 days and 90 days, with a 14-day idle timeout for admin tokens.*
3. **Q3.** Absolute admin web session lifetime? *Recommended default: 12 h absolute, 120 min idle.*
4. **Q4.** May unverified-email accounts log in? *Recommended default: yes, but with restricted actions.*
5. **Q5.** Is sign-up by phone or by social login allowed, or only login for existing accounts? *Recommended default: allow both for public users; admins never sign up.*
6. **Q6.** Which SMS/OTP provider and which countries? Cost approval is needed.
7. **Q7.** Mandatory 2FA for admins? *Recommended default: yes, in the phase after the core rollout.*

## Related ADRs and references

- [ADR-0002](0002-account-classification-and-admin-access.md), [ADR-0004](0004-authorization-with-spatie-permission.md), [ADR-0009](0009-audit-logging-and-security-controls.md)
- Laravel 13 Sanctum documentation (SPA authentication, API tokens); Laravel Socialite documentation
