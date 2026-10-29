# ADR-0009: Audit logging, rate limiting, and CAPTCHA

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Audit log and rate limits are foundational; the CAPTCHA provider is deferrable (the contract is decided now)

## Context

- **[Fact]** There is no audit logging and no custom rate limiters. Laravel's default `throttle` middleware is available. `CACHE_STORE=database` backs the rate limiter today.
- **[Fact]** The infrastructure hardening is already in place:
  - **PHP:** `disable_functions`, `open_basedir`, `allow_url_include=Off`, `expose_php=Off`
  - **Apache:** no CGI, only `index.php` executes, dotfiles denied
  - **Container:** non-root, read-only rootfs, `cap_drop: ALL`
  - **CI:** asserts these properties
- **[Fact]** Candidate packages, all compatible with Laravel 13:
  - `spatie/laravel-activitylog` 5.1.3 (requires PHP ^8.4)
  - `owen-it/laravel-auditing` v14.0.6
- Sensitive operations to audit include user changes, status changes, role and permission grants, category assignments, and critical configuration.

## Problem statement

How are security-relevant actions recorded reliably and privately, and how are authentication flows protected against brute force and automation?

## Decision

### 1. Audit log: custom, append-only, event-driven (Audit module)

**`audit_logs` table:**

- `id` (BIGINT), `occurred_at` (TIMESTAMP(6)), `event` (string key, e.g. `user.banned`, `role.permissions_synced`, `auth.login_failed`)
- `actor_type` (`user|system|cli`), `actor_id` (nullable FK users, `SET NULL`)
- `subject_type` + `subject_id` (target, nullable)
- `outcome` (`success|denied|failed`)
- `context` (JSON: changed fields with before/after for **non-sensitive** fields only, role/permission keys, category IDs, reason codes)
- `ip` (VARBINARY(16)), `user_agent` (VARCHAR(255), truncated), `request_id` (correlates with the [ADR-0006](0006-api-contract-and-versioning.md) `trace_id`), `client_platform`

**Recording:**

- Module Actions emit domain events (`UserBanned`, `RolePermissionsSynced`, `CategoriesAssigned`, `AccountTypeChanged`, …).
- Audit listeners write the rows **synchronously inside the same DB transaction** for security events, so an action cannot succeed without its audit record.
- Spatie Permission's v7+ events (`RoleAttachedEvent`, `PermissionAttachedEvent`, …; enabled in config) catch any assignment made outside the Actions.
- Login successes and failures, lockouts, OTP sends, and token revocations are recorded by Identity.

**Never logged:** passwords, password hashes, tokens, OTP codes, API keys, full request bodies, session IDs, or other secrets. The `context` builder uses an **allow-list** of fields per event; it does not blacklist.

**Append-only:**

- No update or delete API and no Eloquent `update` on the model (the model throws).
- In production, the DB user used by the app should lack `UPDATE`/`DELETE` on `audit_logs`. This is an operational hardening step (Q2).

**Access:**

- `GET /api/v1/admin/audit-logs` requires `audit.view`. It supports filters (actor, subject, event, date) and cursor pagination.
- Audit access is itself audited.

**Retention:** 24 months by default (Q1). A scheduled job archives or deletes older rows; that job is the only deleter.

**Privacy:** IPs and user agents are personal data. Retention and access restrictions apply, and subject-erasure requests anonymize `actor_id` references where the law requires (Q1).

**Why custom rather than a package?**

- Model-diff auditing (`owen-it/laravel-auditing`) records Eloquent attribute changes. Most security events here are **not** attribute changes: pivot grants, logins, token revocations, denied attempts.
- `spatie/laravel-activitylog` can log arbitrary events and is a reasonable alternative, but it requires raising the `composer.json` PHP floor to ^8.4 and has a generic schema (`log_name`, `properties`). The custom table is about 150 lines, has security-specific columns, and is indexable on `(subject_type, subject_id)`, `(actor_id)`, and `(event, occurred_at)`.

### 2. Rate limiting (Core + Identity)

Named limiters, defined in Core and applied per route group:

| Limiter | Key | Limit |
|---|---|---|
| `login` | identifier (normalized) + IP | 5/min per identifier+IP; 20/min per IP; progressive lockout after 10 failures per identifier in 15 min (audited, uniform response) |
| `otp-send` | destination + IP | 1/60 s per destination, 5/h per destination, 10/h per IP |
| `otp-verify` | challenge | 5 attempts per challenge (then the challenge is invalid) |
| `password-reset` | email + IP | 3/h per email, 10/h per IP |
| `register` | IP | 10/h per IP |
| `api` (authenticated) | user ID | 120/min |
| `api-public` | IP | 60/min |
| `admin-sensitive` (role/permission/type changes) | user ID | 20/min |

- Responses are `429 RATE_LIMIT_EXCEEDED` with `Retry-After`.
- **[Recommendation]** Move the cache to **Redis** before production. The database-backed limiter works, but adds write load on MySQL for every request.
- **Trusted proxies** must be configured correctly when behind a load balancer or CDN, otherwise every client shares one IP (Q3).

### 3. CAPTCHA / human verification (contract now, provider later)

- Core defines `HumanVerifier::verify(string $token, string $action, ?string $ip): VerificationResult`, with these drivers:
  - `null`: always passes; local and tests only
  - `fake`: deterministic; used in tests
  - one provider driver chosen later: Cloudflare Turnstile, Google reCAPTCHA v3/Enterprise, or hCaptcha (Q4)
- Verification is **server-side only**. The token goes in the `X-Captcha-Token` header. Secrets live in env/secret storage, never in clients.
- **Where it applies:**
  - registration
  - password-reset request
  - OTP send
  - login **after** the first failed attempts for an identifier or IP (adaptive), not on every login
- Mobile apps: reCAPTCHA Enterprise for mobile, or platform attestation (Play Integrity / App Attest) as the mobile equivalent, decided with the provider.
- **Provider outage:**
  - **fail closed** for registration and OTP send (cost and abuse risk)
  - **fail open with stricter rate limits** for login, so legitimate users aren't locked out
  - the outage is logged and alerted
- CAPTCHA **supplements** rate limiting and lockout; it never replaces them.

### 4. Other security controls confirmed or required

- **Security headers:** `X-Content-Type-Options`, `X-Frame-Options`, and `Referrer-Policy` are already set by Apache. Add `Strict-Transport-Security` at the TLS terminator.
- **Re-confirmation:** password re-confirmation for sensitive admin actions ([ADR-0004](0004-authorization-with-spatie-permission.md)).
- **Secrets:** `.env` is excluded from git and the image. In production, use the orchestrator's secrets. `APP_KEY` rotation is documented using Laravel's `APP_PREVIOUS_KEYS`.
- **Dependencies:** `composer audit` already runs in CI. Add `npm audit` once the frontend joins the repo.

## Alternatives considered

| Option | Verdict |
|---|---|
| `spatie/laravel-activitylog` | Viable; second choice. Requires the PHP ^8.4 constraint. |
| `owen-it/laravel-auditing` | Model-centric; misses non-model security events. Rejected as the primary tool. |
| Logging audit events to files/stdout only | Not queryable from the admin UI, and retention depends on infrastructure. Keep stdout security logs **in addition** for the SIEM. |
| CAPTCHA on every login | Hurts UX and accessibility. Adaptive application is preferred. |

## Reasons and trade-offs

A purpose-built audit table, written in the same transaction, gives reliability and privacy control at a small cost in code. Adaptive CAPTCHA balances abuse resistance against usability.

## Database implications

`audit_logs` with these indexes:

- `(occurred_at)`
- `(actor_id, occurred_at)`
- `(subject_type, subject_id, occurred_at)`
- `(event, occurred_at)`

Expect high row counts. Consider monthly partitioning on `occurred_at` later.

## Security implications

- Complete records of privilege changes.
- No secrets in logs.
- Restricted, audited audit-log access.
- Brute-force resistance.
- Bot resistance on costly flows.

## Performance implications

- One insert per audited action, inside the transaction (microseconds).
- Login-failure logging under attack is bounded by the rate limiters.
- Redis is recommended for the limiter.

## Migration implications

`audit_logs` is created early (phase 1), so the first admin's creation is already audited.

## Testing requirements

- Every sensitive action writes exactly one audit row, with the correct actor, subject, and outcome.
- Denied attempts are logged as `denied`.
- No forbidden fields (password, token) appear in `context`: a test scans the rows.
- The audit model rejects updates.
- Rate limits return 429 with `Retry-After` for each limiter.
- Lockout keeps a uniform response.
- CAPTCHA: the `fake` driver rejects, and fail-closed/fail-open behavior is tested per flow.

## Consequences

Each module must emit events for its sensitive actions; review checklists include "audited?".

## Open questions

1. **Q1.** Audit retention period and legal or regulatory requirements (country)? *Recommended default: 24 months.*
2. **Q2.** Can production use a separate DB user without `UPDATE`/`DELETE` on `audit_logs`?
3. **Q3.** Will a CDN or load balancer front the API (trusted proxy configuration)?
4. **Q4.** CAPTCHA provider preference? *Recommended default: Cloudflare Turnstile for web (privacy-friendly), with the mobile approach chosen alongside it.*

## Related ADRs and references

- [ADR-0002](0002-account-classification-and-admin-access.md), [ADR-0003](0003-authentication-with-sanctum.md), [ADR-0004](0004-authorization-with-spatie-permission.md), [ADR-0006](0006-api-contract-and-versioning.md)
- OWASP Authentication and Logging Cheat Sheets
