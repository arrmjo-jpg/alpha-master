# ADR-0012: Administrative Workspaces

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Foundational. The admin gate (ADR-0002) and the route layout (ADR-0006) depend on it.
- **Source:** the "ADR Update: User Management, Authentication, Authorization, Modular Workspaces, and Notification Center" brief.
  - **[Limitation]** The copy of that brief received was cut off in §6.2, so its detailed Workspace and Notification Center sections were not available.
  - This ADR is derived from §2 principle 8 ("independent functional boundaries for administrative Workspaces") and §3.1 step 3 ("Workspace access: determining which administrative areas the account can enter"). It must be re-checked against the full text.

## Context

- **[Fact]** No admin area, admin routes, or navigation exist in the repository.
- The brief adds a fifth step to the admin authorization chain:

  | Step | Question |
  |---|---|
  | 1. Authentication | Who are you? |
  | 2. Classification | Is this an admin account? |
  | 3. **Workspace access** | Which admin areas may this account enter? |
  | 4. Permission | May it perform this operation? |
  | 5. Resource scope | May it touch this record? |

- Examples of admin areas implied by the briefs:
  - users and access control
  - content/news
  - media
  - settings
  - notifications
  - audit/reports
- The React admin app and the admin mobile app need to know which areas to show.

## Problem statement

What is a Workspace, how does it relate to modules, roles, and permissions, and how is access to it granted and enforced without creating a second authorization system?

## Decision

### 1. Definition

A **Workspace** is a named administrative area: a functional boundary in the admin application, with its own:

- navigation entry
- set of admin API endpoints
- set of permission groups

A Workspace is **not** a tenant, not a separate database, and not a separate guard.

| Concept | What it is | Example |
|---|---|---|
| Module ([ADR-0001](0001-modular-monolith.md)) | A code and ownership boundary in the backend | `Users`, `AccessControl`, `Content`, `Media` |
| **Workspace** | An admin-facing functional area | `users` (people and access), `content`, `media`, `notifications`, `settings`, `audit` |
| Permission group ([ADR-0004](0004-authorization-with-spatie-permission.md)) | A UI grouping of permissions | `users`, `roles`, `permissions`, `news`, … |

**Mapping rules:**

- A module declares the Workspace its admin endpoints belong to.
- One Workspace may cover several modules. For example, the `users` workspace covers the Users and AccessControl admin endpoints.
- Each admin endpoint belongs to exactly one Workspace.
- A permission group belongs to exactly one Workspace.

### 2. Workspace registry (code-defined, with database metadata)

- **Workspaces are declared in code** by the owning module, using a stable key that is lowercase kebab-case and never translated.
- They are mirrored to a **`workspaces` table** by the same `access:sync-permissions` command ([ADR-0004](0004-authorization-with-spatie-permission.md)). The table has:
  - `id`, `key` (unique)
  - `name` (JSON `{ar,en}`), `description` (JSON, nullable)
  - `icon` (string token for clients), `sort_order`
  - `is_enabled`
  - timestamps
- `permission_groups` gets `workspace_id` (FK, `RESTRICT`).
- **[Alternative kept open]** If the product owner prefers no new table, the registry can be config-only, with translations in language files. A table is recommended so that admins can edit labels and the order, consistent with the other translated metadata.

### 3. Access is granted with a Spatie permission (no new mechanism)

- Each Workspace has one access permission: **`workspaces.<key>.access`**. Examples: `workspaces.users.access`, `workspaces.content.access`.
- The sync command generates it automatically, places it in a `workspaces` permission group, and **never grants it automatically**, except to the protected `system-owner` role, which holds every permission.
- Roles bundle a Workspace's access permission with the operation permissions in it. The starter role `content-manager`, for example, holds `workspaces.content.access` plus the `news.*` permissions it needs.
- **Access and operation permissions are independent.** Holding `news.update` without `workspaces.content.access` doesn't let the user in. Holding the access permission alone shows the Workspace but allows no operation.
  - The role editor in the admin UI warns about both inconsistencies.
  - Assignment applies the grant ceiling ([ADR-0004](0004-authorization-with-spatie-permission.md) §5).

**Why an explicit permission instead of deriving access from "holds any permission in this workspace"?**

- Derived access changes silently when a permission is added to a group.
- It cannot express "may enter, but read-only", or "temporarily blocked from this area".
- It makes "who can enter the content area?" an expensive question.

An explicit permission is auditable, testable, and reuses Spatie's cache, middleware, and events.

### 4. Enforcement

Admin routes are grouped per Workspace:

```
/api/v1/admin/{workspace}/{resource}...
e.g. /api/v1/admin/users/users/{id}, /api/v1/admin/users/roles, /api/v1/admin/content/news
```

The middleware chain for every admin route (it extends [ADR-0002](0002-account-classification-and-admin-access.md) §5):

1. `auth:sanctum`
2. `EnsureAccountIsUsable`
3. `EnsureAdminAccount`
4. `EnsureAdminClient`
5. **`workspace:<key>`**: requires that the Workspace is enabled and that the user holds `workspaces.<key>.access`. Otherwise it returns 403 `WORKSPACE_ACCESS_DENIED`.
6. A per-operation permission, through a policy or `can:` middleware.
7. A resource-scope policy where applicable ([ADR-0005](0005-category-level-authorization.md)).

Rules:

- The Core route macro `Route::adminWorkspace('content', fn () => ...)` applies steps 1–5 automatically, so a module can't forget them.
- An architecture test asserts that every route under `/api/v1/admin` passes through the `workspace:` middleware.
- Cross-workspace data lookups (for example, the user picker inside the Content workspace) are served by **narrow lookup endpoints** in the requesting Workspace. They return minimal fields (`id`, `username`, `name`). They do not reuse the other Workspace's full endpoints, so a content manager doesn't need `workspaces.users.access` to pick an author.

### 5. Client contract

- `GET /api/v1/auth/me` (admin clients) adds a `workspaces` array, containing only the Workspaces the user may enter, in `sort_order`:

  ```json
  { "key": "content", "name": "المحتوى", "icon": "newspaper", "permissions": ["news.view", "news.create"] }
  ```

  It holds localized names and the user's effective permissions **within** each Workspace, so React and the admin mobile app can build their navigation.
- This is for display only. The server enforces §4.

### 6. Disabling a Workspace

- `is_enabled=false`, set by `system.settings.update` and audited, makes the `workspace:` middleware return 403 for everyone and removes the Workspace from `/me`.
- Permissions and role assignments stay in place, consistent with module disabling ([ADR-0001](0001-modular-monolith.md)).

## Alternatives considered

| Option | Verdict |
|---|---|
| A separate `workspace_user` assignment table | A second authorization system beside Spatie, with its own UI, cache, and audit. Rejected. |
| Derive access from any held permission in the Workspace | Implicit and hard to audit (§3). Rejected. |
| Spatie *teams* as Workspaces | Teams scope roles per team, so every permission check would need a team context. Workspaces are areas, not tenants. Rejected. |
| A separate guard or user provider per Workspace | Contradicts "no multiple guards without justification", and duplicates identity. Rejected ([ADR-0003](0003-authentication-with-sanctum.md) §6). |
| Workspaces purely in the frontend (navigation config) | The frontend can't enforce access. Rejected as the source of truth; the frontend only renders what `/me` returns. |

## Reasons and trade-offs

Workspaces get explicit, auditable entry control with no new authorization engine: just one permission per Workspace and one middleware. The cost is that role design must include the access permission, which the role editor and a validation warning mitigate.

## Database implications

- New `workspaces` table (AccessControl module).
- `permission_groups.workspace_id` FK.
- Generated `workspaces.<key>.access` permissions.

## Security implications

- One more independent layer before operations.
- The architecture test prevents admin routes without a Workspace.
- Narrow lookup endpoints prevent cross-workspace data leakage.
- Disabling a Workspace is audited.

## Performance implications

The `workspace:` check is a cached permission lookup. `/me` builds the Workspace list from the Spatie cache.

## Migration implications

- Created with AccessControl in phase 1.
- `workspace_id` is added to `permission_groups` in the same migration set.

## Testing requirements

- Admin without the access permission: 403 on every route of that Workspace.
- Access permission without the operation permission: 403 on the operation, but the Workspace is listed in `/me`.
- A disabled Workspace returns 403 for `system-owner` too.
- `/me` lists only the permitted Workspaces, with names in `ar` and `en`.
- Architecture test: every admin route has the `workspace:` middleware.
- Lookup endpoints return only minimal fields.

## Consequences

- The admin API URLs include the Workspace segment.
- Module providers must declare their Workspace.
- The React and mobile admin apps build their navigation from `/me`.

## Open questions

1. **Q1.** The definitive Workspace list for launch. *Proposed:* `users` (users, roles, permissions), `content` (news/articles, categories), `media`, `notifications`, `settings`, `audit`.
2. **Q2.** Can the product owner provide the **full** text of the update brief? The Workspace and Notification Center sections after §6.2 were not received.
3. **Q3.** Should Workspace labels and order be editable by admins (table), or fixed in code (config + language files)?

## Related ADRs and references

[ADR-0001](0001-modular-monolith.md), [ADR-0002](0002-account-classification-and-admin-access.md), [ADR-0003](0003-authentication-with-sanctum.md), [ADR-0004](0004-authorization-with-spatie-permission.md), [ADR-0005](0005-category-level-authorization.md), [ADR-0006](0006-api-contract-and-versioning.md)
