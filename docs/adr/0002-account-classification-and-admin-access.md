# ADR-0002: Account classification, account status, and administrative access

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Foundational

## Context

- **[Fact]** The `users` table has only `id`, `name`, `email` (unique), `email_verified_at`, `password`, `remember_token`, `created_at`, and `updated_at`.
- **[Fact]** There is **no `is_writer` column**, no account-type field, no status field, no roles, and no permissions. The `User` model declares `#[Fillable(['name', 'email', 'password'])]` and `#[Hidden(['password', 'remember_token'])]` (Laravel 13 PHP attributes).
- The product requires three account categories (**Admin**, **Writer**, **Normal user**), with these rules:
  - Only Admins may enter the admin environment.
  - Admin status grants **no** permissions by itself.
  - Writers do not get admin access.
  - Normal users can never become admins through login or client manipulation.

## Problem statement

How should the backend represent the account category, the active/banned status, and admin-environment access, keeping them separate from authentication, roles, permissions, and resource scope, and enforcing them only on the server?

## Decision

### 1. Six separate concerns

| Concern | Mechanism | Owner |
|---|---|---|
| Authentication (who are you?) | Sanctum session or token ([ADR-0003](0003-authentication-with-sanctum.md)) | Identity |
| **Account classification** (what kind of account?) | `users.account_type` column | Users |
| **Account status** (may this account act at all?) | `users.deactivated_at`, `users.banned_at` | Users |
| **Admin-environment access** | `account_type = admin` **and** an admin-capable client ([ADR-0003](0003-authentication-with-sanctum.md)) | Users + Identity middleware |
| **Workspace access** (which admin areas?) | The `workspaces.<key>.access` permission ([ADR-0012](0012-administrative-workspaces.md)) | AccessControl |
| Operation authorization | Spatie permissions through roles or direct grants ([ADR-0004](0004-authorization-with-spatie-permission.md)) | AccessControl |
| Resource scope | Category assignments ([ADR-0005](0005-category-level-authorization.md)) | Content |

### 2. Classification column

**[Recommendation]** Add `account_type VARCHAR(16) NOT NULL DEFAULT 'user'`, backed by the PHP enum `AccountType: string { Admin = 'admin'; Writer = 'writer'; User = 'user'; }` and indexed.

- Not mass-assignable. It is excluded from every Form Request and set only by the `Users\Actions\ChangeAccountType` action.
- That action requires the `users.change-account-type` permission, an admin caller, the last-admin invariant (§6), and an audit record ([ADR-0009](0009-audit-logging-and-security-controls.md)).
- Promotion **to** admin also requires a recent password confirmation by the actor (Laravel `password.confirm` semantics through the API).
- A user can never change their own account type.

**Why not roles for classification?** Roles are bundles of permissions that admins can assign. If "admin" were a role, anyone holding `users.assign-roles` could create admins, and the environment boundary would collapse into the permission system. A separate column with its own protected action keeps the boundary independent.

**Why not booleans (`is_admin`, `is_writer`)?** Booleans allow undefined combinations and repeat the same problem with every new type. A single enum makes the categories mutually exclusive. Whether an admin may also be a writer is **an open question** (Q1). If the answer is yes, the recommended change is a separate `can_publish_as_writer` capability (or a writer *role* in the public scope), not a second classification value.

### 3. Writer

Writers have `account_type = writer`. Writer-only features (submitting or editing their own articles in the public app) are authorized by **public-scope permissions** given through a protected `writer` role assigned automatically when the account type becomes `writer`. They also pass through category scope where relevant. Writer status never reaches `/api/v1/admin/*`.

### 4. Active versus banned

| | `deactivated_at` (nullable timestamp) | `banned_at` (nullable timestamp) |
|---|---|---|
| Meaning | Account switched off: by an admin, by the user (self-deactivation, if the product wants it), or pending activation | Punitive block by an admin for a policy violation |
| Extra columns | `deactivated_by` (nullable FK users) | `banned_by` (nullable FK users), `ban_reason` (TEXT, internal only), `banned_until` (nullable timestamp for temporary bans) |
| Password / OTP / social login | Rejected with `ACCOUNT_INACTIVE` | Rejected with `ACCOUNT_BANNED` |
| Existing sessions and tokens | Revoked immediately | Revoked immediately |
| Password reset / recovery | Allowed (so the user can regain access), but does **not** reactivate | Blocked; the reset flow returns the same generic response as for unknown accounts to avoid enumeration |
| Reversal | `users.activate` | `users.unban` |

Timestamps are better than booleans here, because they record *when* without extra columns. History (repeated bans) goes into the audit log rather than a separate bans table; a `user_bans` history table is an optional later addition.

**Enforcement points (server side only):**

1. **At login**, in every login method, after the credential check and before any token or session is issued. The response does not reveal whether the password was right; checks run in a fixed order with equal-cost hashing.
2. **On every authenticated request**, through `EnsureAccountIsUsable` middleware, which is part of the global `auth:sanctum` group. It covers status changes made between token revocation and the next request, and rows replicated from backups.
3. **On status change:** the `UserDeactivated` and `UserBanned` events make Identity:
   - delete all of the user's `personal_access_tokens` rows,
   - delete all of the user's `sessions` rows ([Fact] `SESSION_DRIVER=database`, so sessions can be found by `user_id`),
   - write audit entries.

### 5. Admin-environment gate

Every `/api/v1/admin/*` route goes through, in order:

1. `auth:sanctum`
2. `EnsureAccountIsUsable`
3. `EnsureAdminAccount` (`account_type === admin`, or 403 `ADMIN_ACCESS_REQUIRED`)
4. `EnsureAdminClient`: the request comes from the admin SPA origin (stateful session) or from a token carrying the `client:admin` ability ([ADR-0003](0003-authentication-with-sanctum.md))
5. `workspace:<key>`: the user must hold `workspaces.<key>.access` ([ADR-0012](0012-administrative-workspaces.md))
6. the **per-route permission** check (`can:<permission>` middleware or a policy)
7. a resource-scope policy where applicable ([ADR-0005](0005-category-level-authorization.md))

There is **no `Gate::before` super-admin bypass.** Steps 3–5 only open doors; step 6 decides each operation, and step 7 decides each record.

### 6. Initial admin, last-admin protection, and recovery

- **Bootstrapping:** the Artisan command `users:create-admin`, run in the container (`docker exec -it AlphaMaster-API php artisan users:create-admin`). It:
  - prompts for the email and username,
  - reads the password **from a hidden prompt** (never from arguments, environment, or logs),
  - creates `account_type=admin` with `email_verified_at` set,
  - assigns the protected `system-owner` role, which holds every catalog permission (re-synced on each deploy),
  - writes an audit entry with actor `system:cli`.

  There is **no HTTP endpoint** that can create the first admin, and no seeder creates an admin with a default password.
- **Invariant:** at least one user is `account_type=admin`, not deactivated, not banned, and holds `system-owner`. It is checked inside a DB transaction with `SELECT … FOR UPDATE` on the admin rows by every action that can break it:
  - change type
  - deactivate
  - ban
  - delete
  - remove role
  - edit the `system-owner` role's permissions (denied outright: `system-owner` is protected and synced from the catalog)
- **Self-protection:** an admin cannot deactivate, ban, delete, or demote themselves, and cannot remove their own `system-owner` role.
- **Recovery:** if all admins are lost (for example, a database restore), the same CLI command, run with server access (the trust boundary), creates or re-enables an owner after a typed confirmation. It is fully audited. No hidden or HTTP "break-glass" path exists.

## Alternatives considered

- **Classification through Spatie roles only:** rejected, because it collapses the boundary into assignable data (§2).
- **Boolean flags:** rejected, because of invalid combinations.
- **Single `status` enum (`active|inactive|banned`):** simpler, but loses the independent dimensions (an account can be banned *and* deactivated, with different reversal owners) and needs extra columns for when/who anyway. Kept as an acceptable fallback.
- **Separate admin users table or admin guard:** strong isolation, but duplicates identity, password reset, and profile logic, and conflicts with "an account may use multiple login methods". Rejected; the classification gate plus the admin-client check gives the needed isolation.

## Reasons and trade-offs

The column-plus-protected-action approach costs one indexed column and two middlewares, and makes "Admin ≠ all permissions" testable. The trade-off is that admin users need an explicit role; the `system-owner` role, synced from the catalog, covers the operational need without a code bypass.

## Database implications

`users` gets the following columns:

- `account_type` (indexed)
- `deactivated_at`, `deactivated_by`
- `banned_at`, `banned_by`, `banned_until`, `ban_reason`

The self-referencing foreign keys use `ON DELETE SET NULL`.

## Security implications

- **Mass-assignment defense:** the existing `#[Fillable]` allow-list must stay explicit and must never include `account_type` or the status columns. `password` is currently fillable; it must only ever be set through dedicated actions, never from a generic update payload.
- Account type is never accepted from login, registration, or social callbacks. Registration always creates `user` (or `writer`, if the product enables writer sign-up; see Q2).
- Status checks happen on every request, not only at login, so a stolen token becomes useless once the account is banned.

## Performance implications

The middleware reads the already-loaded `User` model (Sanctum loads it), so there is no extra query. Revocation on ban is one `DELETE` per table.

## Migration implications

A single additive migration on `users`: new columns, nullable or defaulted, and safe online on MySQL 8.4 (`ALGORITHM=INSTANT` for added columns). Existing rows become `user`/active, and the first admin is created afterwards by the CLI.

## Testing requirements

- An Admin without `users.delete` gets 403 on delete.
- A Writer or User calling any `/admin/*` route gets 403.
- A public-client token with `account_type=admin` gets 403 on `/admin/*`.
- After a ban, all of the user's tokens and sessions are gone, and a reused old token gets 401.
- Banned or inactive login gets the right error code for each method (password, OTP, social).
- The last-admin invariant holds under concurrent requests (two parallel demotions).
- Self-demotion and self-ban are rejected.
- `account_type` in the request payload is ignored or rejected on every endpoint.

## Consequences

Admins must be given roles explicitly, so operations staff need a short "role design" step. The admin UI needs a clear "type" versus "roles" distinction.

## Open questions

1. **Q1.** Can one person be both Admin and Writer? *Recommended default: no; use separate accounts.*
2. **Q2.** Can people self-register as Writers, or does an admin promote them? *Recommended default: admin promotion only.*
3. **Q3.** Should users be able to deactivate their own accounts, and should that differ from deletion (GDPR-style erasure)? *Recommended default: self-deactivation yes; erasure handled as a separate, later process.*
4. **Q4.** Are temporary bans (`banned_until`) needed in phase 1? *Recommended default: keep the column, but no UI until requested.*

## Related ADRs and references

[ADR-0003](0003-authentication-with-sanctum.md), [ADR-0004](0004-authorization-with-spatie-permission.md), [ADR-0005](0005-category-level-authorization.md), [ADR-0009](0009-audit-logging-and-security-controls.md)
