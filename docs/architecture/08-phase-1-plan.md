# Phase 1 — Users & Access Control: verified state, plan, and decisions needed

- **Status:** Step 2 report. **Awaiting approval. No code has been changed.**
- **Date:** 2026-10-29

## 1. Verified current state (Step 1)

| Area | Verified fact |
|---|---|
| Git | `main` has 2 commits (`a46a55e`, `650d7ea`). The only uncommitted change is the `docs/` folder (the ADR work). |
| Framework | Laravel 13.39.0; PHP 8.5.12 runtime; `composer.json` requires `php ^8.3`. |
| DB | MySQL 8.4.11 in Docker. `phpunit.xml` forces SQLite `:memory:`. `api/src/.env.example` uses SQLite. |
| User model | `#[Fillable(['name','email','password'])]`, `#[Hidden(['password','remember_token'])]`, `password` cast `hashed`, no roles, no status. |
| `users` table | `id`, `name`, `email` (unique), `email_verified_at`, `password`, `remember_token`, timestamps. **No `is_writer`, `username`, `account_type`, or status.** |
| Auth | Only the `web` session guard. No Sanctum, no API routes, no `routes/api.php`. |
| Exceptions | `bootstrap/app.php` renders JSON for `api/*`, in Laravel's default shape. |
| Localization | No `lang/` folder. Locale `en`, fallback `en`. |
| Providers | `bootstrap/providers.php` lists only `AppServiceProvider`. |
| Tests | Two example tests. PHPUnit 12. Factory creates verified users with password `password`. |
| Docker | Hardened `php:8.5-apache` image (non-root, read-only, `disable_functions` incl. `proc_open`/`putenv`/`mail`, `open_basedir`). The production image has **no dev dependencies**, so tests cannot run inside it. |
| CI | `.github/workflows/ci.yml`: Laravel job (audit, Pint, tests on SQLite) and Docker job (build, migrate, smoke and security checks). **The CI result has never been verified** (private repository, no `gh` login). |
| ADRs | 0001–0012, all **Proposed**, none approved. ADR-0013 (Notification Center) does not exist. It is out of scope for this phase anyway. |

## 2. Contradictions and gaps found in the ADRs (resolved by recommendation below)

1. **System owner vs. "admin ≠ all permissions"** (ADR-0002 §6, ADR-0004 §4). This one needs a decision; see §4 D1.
2. **Grant ceiling plus new permissions.** If nobody holds a newly added permission, nobody can ever grant it through the API. Whatever bootstrap design is chosen must handle this.
3. **Workspaces** (ADR-0012) add a 5th admin check. The Phase 1 brief lists only 4 checks and excludes navigation UI. Decision D3.
4. **Direct user permissions:** ADR-0004 allows them; the Phase 1 brief says "only if approved". Decision D4.
5. **`users.delete`/restore/force-delete:** in the ADR-0004 catalog, absent from the Phase 1 catalog. **Recommendation:** no deletion endpoints in Phase 1 (deactivate and ban cover the need); no soft-delete column yet.
6. **Writer role:** ADR-0002 proposes an auto-assigned protected `writer` role. Phase 1 has no writer features. **Recommendation:** writer is only `account_type=writer` in Phase 1; no writer role yet.
7. **ADR-0002 factual error** about the fillable list. Corrected in the docs.

## 3. Proposed implementation plan (after approval)

### 3.1 Modules (only these four)

`app/Modules/Core`, `Identity`, `Users`, `AccessControl`. They are registered explicitly in `bootstrap/providers.php`. Media, Content, Audit-as-module, Notifications, and Settings are **not** created. The audit log lives in **Core**, because it is shared infrastructure here, and moves to its own module only if it grows (ADR-0001 is adjusted accordingly).

### 3.2 Dependencies

| Change | Version | Why |
|---|---|---|
| `laravel/sanctum` | ^4.3 (4.3.3 verified: illuminate ^11–^13) | Authentication |
| `spatie/laravel-permission` | ^8.3 (8.3.2 verified: illuminate ^12–^13, php ^8.3) | Roles and permissions |
| `composer.json` `php` | `^8.3` → `^8.5` (**needs approval**, D6) | Match the runtime; it does not unlock anything needed in Phase 1 |
| Translatable JSON | **No package.** A Core cast (about 40 lines) | Avoids `spatie/laravel-translatable`'s PHP ^8.4 floor and an extra dependency |
| Audit | **No package.** A custom `audit_logs` table | Rationale in §3.6 |
| Scramble / OpenAPI | **Deferred** to a later phase | Phase 1 contract is documented in Markdown plus contract tests; adds no dependency now |

### 3.3 Database (migrations)

1. `users`, additive:
   - `username` (VARCHAR 30, UNIQUE, NOT NULL, lowercase)
   - `account_type` (VARCHAR 16, default `user`, INDEX)
   - `deactivated_at`, `deactivated_by` (FK SET NULL)
   - `banned_at`, `banned_by` (FK SET NULL), `ban_reason` (TEXT)
   - `preferred_locale` (VARCHAR 8, nullable)
   - Phone, bio, social links, and avatar are **not** in Phase 1 (out of scope).
2. Sanctum `personal_access_tokens` (published migration).
3. Spatie tables (published migration, v8).
4. `permission_groups` (`key` UNIQUE, `name` JSON, `description` JSON, `sort_order`).
5. Columns added to `permissions`: `permission_group_id` (FK RESTRICT), `display_name` JSON, `description` JSON, `is_deprecated`, `is_sensitive`.
6. Columns added to `roles`: `display_name` JSON, `description` JSON, `is_protected`.
7. `audit_logs` (append-only; indexes on time, actor, subject, event).

Every migration gets a working `down()`. All of this is tested by `migrate:fresh --seed` followed by `migrate:rollback` on MySQL.

### 3.4 Authentication (Sanctum)

| Client | Mechanism | Endpoint |
|---|---|---|
| React admin (web) | Stateful SPA cookie session (CSRF via `/sanctum/csrf-cookie`) | `POST /api/v1/auth/login` |
| Mobile (admin and public) | Per-device personal access token with `expires_at` | `POST /api/v1/auth/tokens` (`device_name`, `client`: `admin`/`public`) |

- **Domains:** production domains are unknown. `SANCTUM_STATEFUL_DOMAINS` defaults to `localhost,localhost:3000,localhost:5173,127.0.0.1`, and CORS allowed origins come from a `CORS_ALLOWED_ORIGINS` env variable (empty in production until decided). **No production domain is invented.**
- **Token lifetimes** come from env (`SANCTUM_ADMIN_TOKEN_TTL_DAYS=30`, `SANCTUM_PUBLIC_TOKEN_TTL_DAYS=90`). Phase 1 has no refresh tokens.
- **Endpoints:** `POST /auth/logout` (current session or token), `GET /auth/me`, `GET /auth/tokens` (own devices), `DELETE /auth/tokens/{id}`, `DELETE /auth/tokens` (all).
- **Login identifier:** email or username, decided by the presence of `@`. Usernames can't contain `@` or be all digits. Uniform `INVALID_CREDENTIALS` response.
- **Admin-client gate:** the admin token ability `client:admin`, or a stateful request from an allowed origin. Admin tokens are issued only to `account_type=admin`.
- **Status enforcement:** checked at login, and by middleware on every authenticated request. Banning or deactivating a user deletes all their tokens and their database sessions in the same transaction.
- **Not in Phase 1:** registration, password reset, email verification, OTP, social login, refresh tokens. Admins create accounts.

### 3.5 Authorization

- Spatie v8, single `web` guard, no wildcard, no teams, **no `Gate::before`**.
- Admin route chain: `auth:sanctum` → `account.usable` → `admin.account` → `admin.client` → permission (policy or `can:`) → resource rules (self-protection, last-admin, grant ceiling).
- Permission identifiers as PHP `BackedEnum`s per module; `access:sync-permissions` command (idempotent).
- **Initial catalog (Phase 1 only):**
  - `users`: `users.view`, `users.create`, `users.update`, `users.activate`, `users.deactivate`, `users.ban`, `users.unban`, `users.change-account-type`, `users.revoke-sessions`
  - `roles`: `roles.view`, `roles.create`, `roles.update`, `roles.delete`, `roles.assign`, `roles.assign-permissions`
  - `permissions`: `permissions.view`
  - `audit`: `audit.view`
- **Escalation guards:**
  - Grant ceiling: you may assign only roles or permissions whose permissions you hold.
  - No self-changes to your own roles, account type, or status.
  - Protected roles are immutable through the API.
  - Admin-only assignment: roles can only be assigned to `account_type=admin`.
- **Cache:** Spatie's own cache. It is flushed automatically by the package on changes, and explicitly after sync. A test verifies the cache reflects changes.

### 3.6 Audit (custom; why not a package)

- `spatie/laravel-activitylog` 5.1.3 is maintained and compatible, but requires **PHP ^8.4** (needs D6), and it models "activity on a subject" with a generic `properties` blob.
- `owen-it/laravel-auditing` audits **Eloquent attribute diffs**. Most Phase 1 security events are not attribute diffs: pivot role/permission changes, logins, token revocations, denied attempts.
- A custom `audit_logs` table (about 150 lines) gives security-specific columns:
  - `event`, `actor_id`, `subject`, `outcome` (`success`/`denied`/`failed`)
  - `context` (an allow-listed JSON), `ip`, `user_agent`, `request_id`
- It is written in the same transaction as the change, with an append-only model.
- If activitylog is preferred, it can replace this after D6 without API changes.

### 3.7 API contract and localization

- `/api/v1/...`. Success: `{ "data": ..., "meta": ... }`. Errors: **decision D2** (RFC 9457 recommended).
- `Accept-Language` → `ar` or `en`; unsupported or missing values fall back to the default locale (**decision D5**). `Content-Language` is set on responses. Error codes are locale-independent.
- `lang/ar` and `lang/en`: validation, auth, and business errors. A key-parity test checks both.
- Production errors never include traces, SQL, or exception classes. A test runs with `APP_DEBUG=false`.

### 3.8 Admin API (Phase 1)

| Endpoint | Permission |
|---|---|
| `GET /admin/users`, `GET /admin/users/{id}` | `users.view` |
| `POST /admin/users` (type is always `user`; changing the type is a separate call) | `users.create` |
| `PATCH /admin/users/{id}` (`name`, `username`, `email`, `preferred_locale` only) | `users.update` |
| `POST /admin/users/{id}/activate`, `/deactivate`, `/ban`, `/unban` | the matching `users.*` |
| `PUT /admin/users/{id}/account-type` (requires the actor's `current_password`) | `users.change-account-type` |
| `DELETE /admin/users/{id}/sessions` | `users.revoke-sessions` |
| `PUT /admin/users/{id}/roles` | `roles.assign` |
| `GET/POST/PATCH/DELETE /admin/roles[/{id}]` | `roles.view` / `.create` / `.update` / `.delete` |
| `PUT /admin/roles/{id}/permissions` | `roles.assign-permissions` |
| `GET /admin/permissions` (grouped, localized) | `permissions.view` |
| `GET /admin/audit-logs` | `audit.view` |

### 3.9 Testing and CI

- **MySQL for the Feature/security suites.** `phpunit.xml` switches to MySQL (`alphamaster_test` database). SQLite is only allowed in a separate `Unit` suite that needs no database.
- **Local test runs:** a new **`dev` target in `api/Dockerfile`** (dev dependencies; not hardened like production) and a `test` Compose profile. The production image is unchanged.
- **Test coverage:** every scenario in sections A–E of the brief, including the **last-admin concurrency test** (two DB connections racing to demote the last two admins with `SELECT … FOR UPDATE`).
- **CI changes:**
  - Laravel job: add a MySQL 8.4 service; run `migrate:fresh --seed` and `migrate:rollback`, `access:sync-permissions` twice (idempotency), and the full test suite.
  - Docker job: add a smoke test for `POST /api/v1/auth/login` returning 401 with the error structure.
  - All existing checks are kept.
- **Not done without authorization:** no push, no branch-protection change.

### 3.10 Files likely to change

| Path | Change |
|---|---|
| `api/src/composer.json`, `composer.lock` | +sanctum, +permission (and the php floor if D6) |
| `api/src/bootstrap/app.php`, `bootstrap/providers.php` | API routing, middleware aliases, exception rendering, module providers |
| `api/src/config/` | `sanctum.php`, `permission.php`, `cors.php` (published), `app.php` (`supported_locales`), `auth.php` unchanged |
| `api/src/app/Models/User.php` | Moves to `Users` or is extended in place (HasApiTokens, HasRoles, casts, fillable) |
| `api/src/app/Modules/**` | **New** |
| `api/src/database/migrations/` | Untouched. New migrations go in the modules. |
| `api/src/database/factories/UserFactory.php` | Username + states |
| `api/src/database/seeders/DatabaseSeeder.php` | Remove the default `test@example.com` user from production paths |
| `api/src/lang/{ar,en}/` | **New** |
| `api/src/phpunit.xml`, `api/src/tests/**` | MySQL test config, architecture tests |
| `api/Dockerfile`, `docker-compose.*.yml` | `dev` target, test profile (production unchanged) |
| `api/src/.env.example`, root `.env.example` | New env vars (`SANCTUM_STATEFUL_DOMAINS`, `CORS_ALLOWED_ORIGINS`, token TTLs, `APP_SUPPORTED_LOCALES`) |
| `.github/workflows/ci.yml` | MySQL service and migration checks |
| `docs/` | ADR updates after approval; Phase 1 API, permissions, and bootstrap docs |

## 4. Decisions needed before implementation (Step 3)

### D1 — Initial system owner / bootstrap (the key contradiction)

**The contradiction:** "Admin must not automatically have all permissions" versus "a `system-owner` role holds all permissions". There is also the gap in §2.2: newly added permissions can't be granted by anyone through the API.

| Option | How it works | Pros | Cons |
|---|---|---|---|
| **A. Protected `system-owner` role, auto-synced, CLI-only (recommended)** | The role holds every catalog permission (re-synced on deploy). It can be **granted or revoked only through the CLI** (`users:create-owner`, `users:grant-owner`, `users:revoke-owner`), never through the API. The holder must be `account_type=admin`. Last-owner protection with row locks. Fully audited. | It doesn't contradict the rule: admins get nothing by **classification**. Ownership is an explicit, revocable role grant that requires **server access**, so API escalation to owner is impossible. It also solves the "new permission nobody holds" gap. | Owners automatically gain future permissions; it is still effectively a super-admin role. This is mitigated by keeping owners few and audited, and by recommending 2FA later. |
| B. Bootstrap set, no auto-sync | The CLI creates the first admin with a fixed starter set (users.*, roles.*, permissions.view, audit.view). New permissions are granted only through the CLI command `access:grant-permission <role> <perm>`. | No role ever silently grows. | Every new module needs a manual CLI step, so operations work goes up. Still safe. |
| C. No special role; grant-ceiling exception for some admins | — | — | Adds a hidden privilege rule. Not recommended. |

**Recommendation: A.** The bootstrap command:
- prompts for the email, username, and name;
- reads the password from a hidden prompt (never from arguments or env);
- refuses to create a second owner unless `--additional` is passed, with a typed confirmation;
- is idempotent: re-running it for an existing owner does nothing except re-sync;
- writes an audit entry with actor `cli`.

There is no seeder with a default admin password and no HTTP bootstrap endpoint.

### D2 — Error contract

RFC 9457 Problem Details (`application/problem+json` with `code`, `errors`, `trace_id`) versus the `{success, message, data, error}` envelope. **Recommendation: RFC 9457** (standard, needs no wrapper around success responses). Either is fine, but it must be frozen now.

### D3 — Workspaces (ADR-0012) in Phase 1?

**Recommendation: defer.** Phase 1 has only one admin area (users and access), and the brief excludes navigation UI. Admin routes go under `/api/v1/admin/...` without a workspace segment. Adding `/admin/{workspace}/` later would be a breaking URL change, so if Workspaces are certain, reserve the segment now (`/api/v1/admin/users/...` doubles as the future `users` workspace). **Proposed compromise:** use the URL shape `/api/v1/admin/users/...` and `/api/v1/admin/access/...` now, with no workspace permission or table yet.

### D4 — Direct user permissions

**Recommendation: not in Phase 1.** Roles only. This avoids a second assignment path and its UI and audit surface. Can be added later if needed.

### D5 — Default locale when `Accept-Language` is missing or unsupported

`ar` or `en`? The current config is `en`. **Recommendation:** `ar` if the primary audience is Arabic-speaking; otherwise keep `en`.

### D6 — Raise `composer.json` PHP floor to `^8.5`

**Recommendation: yes** (matches the runtime and CI). Not strictly required for Phase 1.

### D7 — Commits

Work happens on a feature branch (`phase-1/users-access-control`) with local commits. **Nothing is pushed** without your explicit approval. Or would you prefer no commits at all until you review?

## 5. Approved decisions (owner, 2026-10-29)

| # | Decision | Approved option |
|---|---|---|
| D1 | System owner / bootstrap | **A**: protected `system-owner` role, auto-synced to all catalog permissions, granted and revoked **only through the CLI**; the holder must be an admin; last-owner protection |
| D2 | Error contract | **RFC 9457 Problem Details** (`code`, `errors`, `trace_id`); success is `{data, meta}` |
| D3 | Workspaces | **Deferred**; URL shape `/api/v1/admin/users/...` and `/api/v1/admin/access/...` |
| D4 | Direct user permissions | **Not in Phase 1** (roles only) |
| D5 | Default locale | **Arabic (`ar`)**; fallback for missing translations is `en` |
| D6 | PHP floor | **`^8.5`** |
| D7 | Commits | Local commits on branch `phase-1/users-access-control`; **no push** |

The ADRs stay *Proposed* as whole documents. These approvals apply only to the decisions listed here.
