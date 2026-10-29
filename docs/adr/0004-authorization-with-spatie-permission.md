# ADR-0004: Authorization with Spatie Permission, permission groups, and multilingual metadata

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Foundational

## Context

- **[Fact]** `spatie/laravel-permission` is **not installed**.
- **[Fact]** The latest stable release is **8.3.2** (2026-10-19). It requires `php ^8.3` and `illuminate/auth|contracts|database ^12|^13`, so it is compatible with Laravel 13.39.0 and PHP 8.5.12.
- **[Fact]** v8 is a new major version compared with the widely documented v6. The upgrade notes list these changes:
  - **v7:** service-provider and event/command class renames, removed deprecated methods, stricter type hints, and a changed wildcard contract.
  - **v8:** `findByName()` and `findOrCreate()` accept `BackedEnum|string`.

  The core schema (`permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`, each with a `name` + `guard_name` string) is unchanged since v5. Implementation must follow the **v8 documentation**, not older tutorials.
- Requirements:
  - Language-independent permission IDs
  - Arabic and English display names and descriptions
  - Permission groups
  - No automatic all-permissions for admins
  - Protection against self-escalation
  - Permissions registered by modules

## Problem statement

How should permissions, roles, groups, and their translated metadata be modeled on top of Spatie Permission v8, without changing the package's core identifiers and without unrestricted bypasses?

## Decision

### 1. Spatie v8, one guard, no teams

- **Guard:** use a single `guard_name = 'web'` for all roles and permissions; set `protected string $guard_name = 'web'` on `User`.
  - Sanctum token requests and SPA requests both resolve `User` through the `users` provider.
  - **[Recommendation]** Verify during implementation that `auth:sanctum` requests authorize correctly against `web`-guard permissions, with a test, because guard mismatch is the most common Spatie + Sanctum failure.
- **Teams:** the teams feature stays **off**. Category scope is a separate concern ([ADR-0005](0005-category-level-authorization.md)), not a Spatie team.
- **Wildcard permissions:** **off** (`enable_wildcard_permission=false`). Explicit permissions are auditable; wildcards make "who can do X" hard to answer and expand silently when new permissions appear.
- **Super-admin bypass:** **no `Gate::before` grant-all** of any kind ([ADR-0002](0002-account-classification-and-admin-access.md)).
- **Cache:** use Spatie's built-in permission cache on the default store. **[Fact]** `CACHE_STORE=database` today; Redis is recommended when available. The cache is flushed by the package on role/permission changes.

### 2. Stable identifiers: naming rules

- **Format:** `<module-or-resource>.<action>`, in lowercase kebab-case. Examples: `users.view`, `users.force-delete`, `roles.assign-permissions`, `articles.publish`.
- **Declaration:** each module declares its permissions as a PHP **`BackedEnum`** (for example `Users\Permissions: string { case View = 'users.view'; … }`). This uses v8's `BackedEnum` support, so code never repeats string literals.
- **The `name` column stays a plain string identifier.** It is **never translated** and never turned into JSON.
- **Reserved prefixes:** `system.*` (platform operations) and `client:*` (Sanctum abilities only; never a permission).
- **Deprecation:**
  - A permission removed from code is marked `is_deprecated=true` by the sync command; it is not deleted.
  - It stays assigned, but it is hidden in the UI and ignored by new roles.
  - Deleting it is a separate, explicit command with an audit entry.
  - Names are never reused with a different meaning.

### 3. Initial permission catalog (example; it is finalized per module)

| Group | Permissions |
|---|---|
| `users` | `users.view`, `users.create`, `users.update`, `users.delete`, `users.restore`, `users.force-delete`, `users.activate`, `users.deactivate`, `users.ban`, `users.unban`, `users.change-account-type`, `users.revoke-sessions` |
| `roles` | `roles.view`, `roles.create`, `roles.update`, `roles.delete`, `roles.assign` (assign roles to users), `roles.assign-permissions` |
| `permissions` | `permissions.view`, `permissions.assign-direct` (direct user grants; use sparingly) |
| `workspaces` | `workspaces.<key>.access`, one per Workspace, generated automatically ([ADR-0012](0012-administrative-workspaces.md)) |
| `categories` | `categories.view`, `categories.create`, `categories.update`, `categories.delete`, `categories.assign-users`, `categories.access-all` |
| `news` (content resource; see note) | `news.view`, `news.create`, `news.update`, `news.delete`, `news.publish`, `news.restore`, `news.force-delete` |
| `media` | `media.view`, `media.upload`, `media.delete` |
| `settings` | `settings.view`, `settings.update` |
| `audit` | `audit.view` |

Splitting `users.update` from `roles.assign`/`users.change-account-type` keeps "edit someone's profile" separate from "change someone's power".

**Reconciliation with the update brief.** The update brief's examples use `news.*`, `roles.manage`, `permissions.assign`, and `settings.view/update`. This catalog adopts them as follows:

- **`news.*`** is used for the content resource. Earlier drafts used `articles.*`. Whether "news" and "articles" are the same thing or two resources is open question Q4.
- **`roles.manage`** is **split** into `roles.create`, `roles.update`, `roles.delete`, `roles.assign`, and `roles.assign-permissions`. A single "manage" permission would let someone who only edits role labels also hand out permissions, which is the escalation path §5 guards against. If the product owner prefers a coarse `roles.manage`, `roles.assign-permissions` must still stay separate.
- **`permissions.assign`** maps to `permissions.assign-direct`. The new name makes clear that it covers direct grants to users. Grants through roles use `roles.assign-permissions`.
- **`settings.view`** and **`settings.update`** replace the earlier placeholder `system.settings.update`.

### 4. Metadata: extended Spatie models plus a groups table

Extend the package models through `config/permission.php` (`models.permission` and `models.role` set to custom classes in AccessControl) and **add columns** in a module migration that runs after the Spatie migration.

- **`permission_groups`:**
  - `id`, `key` (VARCHAR(64), unique, stable), `name` (JSON `{ar,en}`), `description` (JSON, nullable)
  - `sort_order`, `module` (owning module), timestamps
- **`permissions` (added columns):**
  - `permission_group_id` (FK, `RESTRICT` on delete), `display_name` (JSON), `description` (JSON, nullable)
  - `is_deprecated` (bool), `is_sensitive` (bool: requires password confirmation to grant, for example `users.change-account-type`, `roles.assign-permissions`)
- **`roles` (added columns):**
  - `display_name` (JSON), `description` (JSON, nullable)
  - `is_protected` (bool): the role cannot be deleted or renamed, and its permissions cannot be edited through the API
  - `scope` (`admin|public`): admin-scope roles can be assigned only to admin accounts; public-scope roles, such as `writer`, only to non-admins. This prevents giving admin permissions to public accounts by mistake.

Rules:

- **About the update brief's JSON example:** the brief shows `{"name": {"ar": …, "en": …}, "description": {…}}`. That example is a **display label**. In this design it is stored in `display_name`, because Spatie's own `name` column must remain the stable identifier (`users.view`). Both briefs require this: "stable technical identifiers independent of display language" and "without creating separate permission records for each language". The API exposes `name` (identifier) and `display_name` (localized label) side by side, so clients never confuse the two.
- **Translated JSON** holds the keys `ar` and `en` (both required at write time) and is read through a Core `TranslatableJson` cast ([ADR-0007](0007-arabic-english-localization.md)). Relationships always use `id` or `key`, never labels.
- **Group deletion:** blocked while it has permissions. Empty groups are allowed (pre-created by modules).
- **The catalog is code; the database mirrors it.** Each module returns its permissions with group and translations from `Permissions.php`. `php artisan access:sync-permissions` (idempotent, run on deploy) upserts groups and permissions, marks missing ones deprecated, and re-syncs the protected `system-owner` role to "all non-deprecated permissions".
  - **Sync never assigns permissions to any other role or user.**
  - Admins can edit translations in the UI. The sync command does not overwrite edited translations if `metadata_locked=true` (an optional column; Q2).

### 5. Role management and escalation safeguards

- Role CRUD requires the `roles.*` permissions. Protected roles (`system-owner`, `writer`) cannot be deleted or edited.
- **Grant ceiling:** a user can only assign a permission they hold themselves, and only assign a role whose permissions are all a subset of their own. This blocks escalation by creating a super-role and assigning it to oneself.
- **No self-assignment:** a user cannot change their own roles or direct permissions.
- **Sensitive permissions** (`is_sensitive`) need a recent password confirmation (≤ 10 min) to grant.
- Every grant and revoke is audited ([ADR-0009](0009-audit-logging-and-security-controls.md)), using the Spatie v7+ events (`RoleAttachedEvent` and the rest; enable them in config) plus application actions.
- **Direct user permissions:** allowed, but discouraged and controlled by `permissions.assign-direct`. Roles are the normal path. The admin UI shows effective permissions, marking which ones are direct and which come through roles.
- **Writer role:** public-scope and protected. Assigned or removed automatically by `ChangeAccountType` ([ADR-0002](0002-account-classification-and-admin-access.md)).

### 6. Effective-permissions API for clients

`GET /api/v1/auth/me` returns:

- `account_type`
- `roles` (`key` + localized `display_name`)
- `permissions`: a flat array of identifier strings

Clients use it only to hide UI elements. The server enforces everything.

## Alternatives considered

| Option | Verdict |
|---|---|
| Translate the Spatie `name` itself (JSON in `name`) | Breaks `findByName`, the cache keys, middleware strings, and uniqueness. Rejected, as the brief also requires. |
| Separate `permission_translations` table (`permission_id`, `locale`, `display_name`, `description`) | Normalized and good for many languages. More joins and more code for two languages. Acceptable alternative if the language count grows past roughly 5. |
| `spatie/laravel-translatable` 6.15.0 for the JSON columns | Compatible with Laravel 13, **but requires PHP ^8.4**, while `composer.json` declares `php ^8.3` (runtime is 8.5, so only the constraint would need raising). The value it adds over a 30-line cast is fallback and query helpers. Optional; see [ADR-0007](0007-arabic-english-localization.md). |
| Laravel Gates and Policies only, without Spatie | Would need custom role/permission tables, caching, and assignment APIs, which is exactly what Spatie provides. Rejected. |
| Super-admin `Gate::before` | Violates the requirement. Rejected. The `system-owner` role covers the need. |

## Reasons and trade-offs

Extending Spatie's own models keeps its features (cache, middleware, `can()`, `BackedEnum` lookup) while adding metadata in the same row, so there are no extra joins for the common "list permissions grouped" screen. The code-as-catalog approach makes permissions reviewable in pull requests.

## Database implications

- **Package tables:** five (via the published migration).
- **Custom table:** one (`permission_groups`).
- **Added columns:** 5 on `permissions`, 4 on `roles`.
- **Constraints:** `(name, guard_name)` stays unique as the package defines it; `permission_groups.key` is unique.

## Security implications

- Grant ceiling, no self-assignment, protected roles, sensitive-permission reconfirmation, audit of every change, no wildcard, no bypass.
- Permission checks happen on the server, in policies or route middleware, for every endpoint.

## Performance implications

The Spatie cache loads all permissions once per cache lifetime. Effective permissions for `/me` come from the cache. Role and permission edits flush the cache, which is acceptable because they are rare.

## Migration implications

- Publish the Spatie migration into AccessControl.
- The metadata migration must have a later timestamp.
- Then run `access:sync-permissions` as a deploy step, after `migrate --force`.

## Testing requirements

- Admin with `users.view` but not `users.delete`: 200 on view, 403 on delete.
- Grant ceiling: assigning a permission you don't hold gets 403.
- A self-role change gets 403.
- The protected role is immutable.
- Sync command: idempotent, marks deprecated permissions, never grants permissions to non-owner roles.
- Guard: a Sanctum-token request against `web`-guard permissions authorizes correctly.
- Both `ar` and `en` are required for translated fields.
- Every role/permission change creates an audit entry.

## Consequences

- The permission catalog becomes part of each module's code review.
- Operations staff must design roles; a starter set (`content-manager`, `user-manager`, `auditor`) can be proposed during implementation.

## Open questions

1. **Q1.** Should admins be able to create permissions through the API, or only roles? *Recommended default: only roles. Permissions come from code.*
2. **Q2.** Should translations edited in the admin UI override the code-defined translations? *Recommended default: yes, through `metadata_locked`.*
3. **Q3.** What is the starter role set for launch?
4. **Q4.** Are "news" and "articles" one content type or two? The first brief says articles; the update brief's examples say `news.*`. *Recommended default: one Content module with a `type` field if both exist, with one permission prefix per type.*
5. **Q5.** Should `roles.manage` be one permission, as in the update brief's example, or split as recommended?

## Related ADRs and references

- [ADR-0001](0001-modular-monolith.md), [ADR-0002](0002-account-classification-and-admin-access.md), [ADR-0005](0005-category-level-authorization.md), [ADR-0007](0007-arabic-english-localization.md), [ADR-0009](0009-audit-logging-and-security-controls.md)
- spatie/laravel-permission v8 documentation and `docs/upgrading.md`
