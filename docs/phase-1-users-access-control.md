# Phase 1 — Users & Access Control (implemented)

- **Branch:** `phase-1/users-access-control` (local only, not pushed)
- **Scope:** backend only. There is no UI, no news or categories, no media, and no notifications.
- **Decisions:** D1–D7 in [08-phase-1-plan.md](architecture/08-phase-1-plan.md) §5.

## 1. Architecture as built

```
api/src/app/Modules/
├── Core/           error codes + RFC 9457 renderer, request id, locale negotiation,
│                   audit log (append-only), permission registry, translations rule, rate limiters
├── AccessControl/  Spatie v8 models (+ ar/en metadata), permission groups, catalog sync,
│                   privilege guard (grant ceiling), roles API, permission catalog, audit-log API
├── Users/          account_type enum, status (deactivate/ban), account-type change,
│                   role assignment, last-admin invariant, system-owner CLI
└── Identity/       login (SPA session + device tokens), logout, /me, token management,
                    per-request status enforcement, admin-client check, credential revocation
```

- **Dependency direction:** Core ← AccessControl ← Users ← Identity. `tests/Unit/ArchitectureTest.php` enforces it.
- **Cross-module side effects:** `Users\Events\CredentialsRevocationRequested` is handled synchronously by Identity, inside the same transaction.
- **Tests location:** tests live in `api/src/tests/` rather than inside the modules, so no test code ends up in the production image. This is a deliberate deviation from the ADR-0001 sketch.

## 2. The authorization chain (every `/api/v1/admin/*` route)

1. `auth:sanctum`: a session cookie (SPA) or a bearer token.
2. `account.usable`: not banned and not deactivated. Otherwise the credential is destroyed and the request gets 403 `ACCOUNT_BANNED` or `ACCOUNT_INACTIVE`.
3. `admin.account`: `account_type = admin`. Otherwise 403 `ADMIN_ACCESS_REQUIRED` (audited).
4. `admin.client`: a token with the `client:admin` ability, or a first-party SPA session.
5. `can:<permission>`: a Spatie permission. **Being an admin grants nothing on its own.**
6. Action rules:
   - not yourself
   - not a system owner (owners are CLI-only)
   - the target's permissions must be a subset of yours
   - grant ceiling
   - last usable admin (with row locks)
   - **role edits and deletes:** the actor must also be able to manage every holder of the role

Steps 2–4 run before route-model binding, so a non-admin always gets 403, never 404.

## 3. API endpoints

All endpoints are JSON under `/api/v1`. Errors use `application/problem+json` (§5).

### Identity

| Method & path | Auth | Notes |
|---|---|---|
| `GET /sanctum/csrf-cookie` | none | SPA only: sets the XSRF cookie |
| `POST /auth/login` | none, `throttle:login` | SPA session login (stateful origins only; otherwise 400 `STATEFUL_REQUEST_REQUIRED`). Body: `identifier` (email or username), `password` |
| `POST /auth/tokens` | none, `throttle:login` | Device token. Body: `identifier`, `password`, `device_name`, `client` (`admin`/`public`). Returns `token` once |
| `POST /auth/logout` | sanctum | Ends the current token or session |
| `GET /auth/me` | sanctum | Profile, `account_type`, roles, effective `permissions` (for UI only) |
| `GET /auth/tokens` | sanctum | The user's own devices (never the secrets) |
| `DELETE /auth/tokens/{id}` | sanctum | Revoke one device |
| `DELETE /auth/tokens` | sanctum | Revoke all devices and sessions |

### Admin: users (`/admin/users`)

| Method & path | Permission | Notes |
|---|---|---|
| `GET /admin/users` | `users.view` | Filters: `account_type`, `status` (`active`/`deactivated`/`banned`), `search`, `per_page` ≤ 100 |
| `GET /admin/users/{id}` | `users.view` | |
| `POST /admin/users` | `users.create` | Always creates `account_type=user`. `account_type`, status, roles and permissions in the body are rejected (`prohibited`) |
| `PATCH /admin/users/{id}` | `users.update` | `name`, `username`, `email` (clears verification), `preferred_locale` |
| `POST /admin/users/{id}/activate` · `/deactivate` | `users.activate` · `users.deactivate` | Deactivating revokes all tokens and sessions |
| `POST /admin/users/{id}/ban` · `/unban` | `users.ban` · `users.unban` | `reason` is required (stored, admin-only, not copied into the audit log) |
| `PUT /admin/users/{id}/account-type` | `users.change-account-type` + `throttle:sensitive` | `account_type`, `current_password`. Leaving `admin` removes all roles; any change revokes credentials |
| `DELETE /admin/users/{id}/sessions` | `users.revoke-sessions` | |
| `PUT /admin/users/{id}/roles` | `roles.assign` + `throttle:sensitive` | `roles` (ids), `current_password`. Admin accounts only; protected roles refused; grant ceiling applies |

### Admin: access (`/admin/access`)

| Method & path | Permission | Notes |
|---|---|---|
| `GET /admin/access/permissions` | `permissions.view` | Grouped and localized. `?include=translations` returns both languages; `?include_deprecated=1` |
| `GET /admin/access/roles` · `GET /admin/access/roles/{id}` | `roles.view` | |
| `POST /admin/access/roles` | `roles.create` | `name` (stable key), `display_name` `{ar,en}` (both required), `description` `{ar,en}` |
| `PATCH /admin/access/roles/{id}` | `roles.update` | Labels only. The key is immutable. Protected roles are refused |
| `DELETE /admin/access/roles/{id}` | `roles.delete` | Grant ceiling applies; protected roles are refused |
| `PUT /admin/access/roles/{id}/permissions` | `roles.assign-permissions` + `throttle:sensitive` | `permissions` (names), `current_password`. Every added **and** removed permission must be held by the actor, and the actor must be able to manage every holder of the role |
| `GET /admin/access/audit-logs` | `audit.view` | Filters: `event`, `outcome`, `actor_id`, `subject_type`, `subject_id`, `from`, `to`. Cursor pagination |

## 4. Permission catalog (17 permissions, 4 groups)

| Group | Permissions (★ = sensitive: the actor must re-enter their password) |
|---|---|
| `users` | `users.view`, `users.create`, `users.update`, `users.activate`, `users.deactivate`, `users.ban`, `users.unban`, `users.change-account-type` ★, `users.revoke-sessions` |
| `roles` | `roles.view`, `roles.create`, `roles.update`, `roles.delete`, `roles.assign` ★, `roles.assign-permissions` ★ |
| `permissions` | `permissions.view` |
| `audit` | `audit.view` |

How the catalog works:

- **Defined in code** as `BackedEnum`s (`Users\Permissions\UserPermission`, `AccessControl\Permissions\AccessPermission`), with Arabic and English labels and descriptions.
- **`php artisan access:sync-permissions`** mirrors the catalog into the database. It is idempotent and runs on every deploy.
  - Permissions removed from code are marked `is_deprecated`; they are never deleted.
  - It re-syncs the protected `system-owner` role to every non-deprecated permission.
  - It **never** grants anything to any other role or user.
- **Deliberately not enabled:** no direct user permissions (D4), no wildcard permissions, no teams, no `Gate::before`.

## 5. Error contract (RFC 9457)

```json
{
  "type": "/docs/problems/validation-error",
  "title": "البيانات المرسلة غير صالحة.",
  "status": 422,
  "code": "VALIDATION_ERROR",
  "errors": { "email": [ { "code": "email", "message": "يجب أن يكون حقل البريد الإلكتروني عنوان بريد إلكتروني صالحًا." } ] },
  "trace_id": "01M64…"
}
```

- `code` and the field rule codes are stable across languages. `title` and `message` follow `Accept-Language`.
- `trace_id` equals the `X-Request-Id` response header.
- 500 responses include a `debug` block only when `APP_DEBUG=true` **and** `APP_ENV=local`. Anywhere else, exception classes, messages, SQL and traces never appear. Both cases are tested.
- The codes live in `App\Modules\Core\Errors\ErrorCode`.

## 6. Localization

- **Language selection:** `Accept-Language` picks `ar` or `en`. Missing or unsupported values fall back to **Arabic** (D5). Missing translations fall back to English.
- **Response headers:** `Content-Language` and `Vary: Accept-Language` are set on every response.
- **Language files:** `lang/{ar,en}/` holds the framework files; `app/Modules/Core/Lang/{ar,en}/` holds error titles and custom rule messages. `LanguageParityTest` fails the build if a key exists in only one language.
- **Database metadata:** permission, group and role labels are stored as JSON `{ar, en}`; the permission `name` is never translated.

## 7. Bootstrapping the first administrator (D1)

```bash
docker exec -it AlphaMaster-API php artisan migrate --force
docker exec -it AlphaMaster-API php artisan access:sync-permissions
docker exec -it AlphaMaster-API php artisan users:create-owner
```

`users:create-owner`:

- prompts for the name, username, and email, plus the password through a **hidden prompt** (never arguments or env);
- asks for confirmation;
- creates an `admin` account with the protected `system-owner` role;
- is audited with actor `cli`;
- refuses to run when an owner already exists, unless `--additional` is passed.

Other owner commands:

- `users:grant-owner {username|email}`: for active admins only.
- `users:revoke-owner {username|email}`: refused for the last active owner.

There is **no HTTP endpoint** for any of these. Owners cannot be demoted, banned, deactivated or re-roled through the API (`OWNER_PROTECTED`).

## 8. Running the checks

```bash
docker compose -f docker-compose.test.yml run --rm --build api-test
```

That command runs the whole PHPUnit suite against an isolated, throwaway MySQL 8.4 container (the `alphamaster-test` project; it never touches AlphaMaster-DB). Variants of the same command:

| Check | Command |
|---|---|
| One test class | `docker compose -f docker-compose.test.yml run --rm api-test vendor/bin/phpunit --filter AuthenticationTest` |
| Code style | `docker compose -f docker-compose.test.yml run --rm api-test vendor/bin/pint --test` |
| Dependency audit | `docker compose -f docker-compose.test.yml run --rm api-test composer audit` |

CI (`.github/workflows/ci.yml`):

- **Laravel job:** `composer audit`, Pint, then on a MySQL 8.4 service:
  - `migrate:fresh --seed`
  - sync twice
  - `migrate:rollback`
  - `migrate`
  - the full PHPUnit suite
- **Docker job:** the hardened image, the existing smoke and security checks, plus a real token flow: login, `/me`, admin endpoints, logout, and a check that the revoked token is rejected.

## 9. Environment variables (new in Phase 1)

| Variable | Default | Purpose |
|---|---|---|
| `APP_LOCALE` | `ar` | Default response language |
| `APP_SUPPORTED_LOCALES` | `ar,en` | Negotiable languages |
| `SANCTUM_STATEFUL_DOMAINS` | localhost variants | Origins that get SPA cookie sessions. **Set the real admin SPA domain in production** (not decided yet) |
| `CORS_ALLOWED_ORIGINS` | empty (none) | Exact SPA origins allowed for credentialed CORS |
| `SESSION_DOMAIN` | unset | e.g. `.example.com` when the SPA and API share a parent domain |
| `SESSION_SECURE_COOKIE` | `false` | **Must be `true` in production (HTTPS)** |
| `SANCTUM_ADMIN_TOKEN_TTL_DAYS` / `SANCTUM_PUBLIC_TOKEN_TTL_DAYS` | `30` / `90` | Device token lifetimes |

**Docker Compose mode:** the default `COMPOSE_FILE` in `.env.example` is production-safe. The dev override (`APP_ENV=local`, `APP_DEBUG=true`, source mounts) is opt-in and must never be used on a server. CI fails if the documented default enables it.

**Audit redaction:** `Core\Audit\SecretRedactor` is applied to every audit context, recursively. It splits each key into words (`bearerToken` and `BEARER_TOKEN` both become `bearer token`) and removes it when:

- any word is always secret (password, pwd, pass, passcode, pin, secret, otp, cookie, session, authorization, bearer, credential, csrf, xsrf, salt); or
- a word is `token`/`jwt` and the key does not end in a reference suffix (`id`, `count`, `type`, `at`, …); or
- `key` follows a credential qualifier (`api_key`, `private_key`); or
- `code` follows a one-time-code qualifier (`otp_code`, `verification_code`).

`token_id`, `tokens` (a count), a plain `key` or `code` and all current context keys are preserved. Values shaped like `Bearer …` or a Sanctum `id|secret` token are dropped under any key.

**CI configuration check:** `.github/ci/check-compose-config.sh` resolves the real Compose configuration from `.env.example` without starting containers. It fails unless the default resolves to `APP_ENV=production`, `APP_DEBUG=false` with no dev override, and the documented dev line resolves to `local`, `true` with the override.

**Password re-confirmation:** the three endpoints that require `current_password` use the `sensitive` limiter (5 per minute and 30 per hour, per user). Each wrong password is audited as `auth.reconfirm_failed`, without the password.

**Role-change serialization (M1):** `ManageRoles::syncPermissions`, `ManageRoles::delete` and `SyncUserRoles` first take `SELECT … FOR UPDATE` on the protected `system-owner` role row (`AccessControlLock`), then re-read the actor, the role, the target and the holders. As a result:

- A holder who is added or strengthened by a concurrent change is always seen by the next check.
- There is a single lock taken first, so there is no lock-ordering cycle and no deadlock between these paths.
- The InnoDB snapshot is taken after the lock wait, so the checks read the latest committed state.

**Versioned permission cache (M2):** `VersionedPermissionRegistrar` replaces Spatie's registrar (through the container's `extend`).

- The cache key is `spatie.permission.cache.<ULID>`. The ULID is stored in the shared cache store under `spatie.permission.cache.version`.
- After a permission change commits (`DB::afterCommit`), a new ULID is written. A request that read the old data can only write it under the old key, which nothing reads again.
- A rollback never changes the version. In nested transactions, the change waits for the outermost commit.
- All instances read the version from the same database or Redis store.

Both behaviors are reproduced with two real processes in `tests/Feature/Concurrency/` (MySQL 8.4).

## 10. Known limitations and follow-ups

- **Production domains are not decided** (ADR-0003 Q1). SPA cookie auth needs the SPA and the API on the same registrable domain.
- **No scheduler container** yet, so expired tokens are not pruned automatically (they are still rejected). Add a scheduler that runs `sanctum:prune-expired`.
- **Rate limiter cache:** the limiter and the permission cache use the database cache store. Redis is recommended before production traffic.
- **Not in Phase 1:** registration, password reset, email verification, phone OTP, social login, refresh tokens, admin 2FA, Workspaces (D3), and direct user permissions (D4).
- **Independent review follow-ups (not blocking):** production-only settings should be required at startup (`SESSION_SECURE_COOKIE`, stateful domains, trusted proxies); re-read and lock the target in the account status and account-type actions (role paths already do this); an admin can still edit their own profile through the admin API; admins without a permission can distinguish existing from missing IDs (403 vs 404); deprecated permissions block managing their holders; the last-admin concurrency test still drives one side with raw SQL.
- **Permission cache in long-running workers:** a queue worker or Octane process builds its registrar once, so it keeps its cache key until it restarts (Spatie resets on Octane requests). No such workers exist yet.
- **`laravel/boost`:** the `CLAUDE.md`/`AGENTS.md` files that came with the Laravel skeleton ask for `laravel/boost` to be installed and for PHP to be installed on the host. Neither was done; that needs the owner's decision.
