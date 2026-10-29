# F. API coverage matrix (proposed; nothing implemented)

**Conventions:**

- All paths are under `/api/v1`.
- "Admin gate" = `auth:sanctum` → account usable → `account_type=admin` → admin client → `workspace:<key>` (ADR-0002, ADR-0012).
- All endpoints:
  - validate with Form Requests
  - return Resources on success and Problem Details on errors (ADR-0006)
  - honor `Accept-Language` (`ar`/`en`) for messages and translated metadata (ADR-0007)

| Module | Operation | Method & path | Auth | Permission | Category scope | Key validation | Localization |
|---|---|---|---|---|---|---|---|
| **Identity** | CSRF cookie (SPA) | `GET /sanctum/csrf-cookie` | none | — | — | — | — |
| | Login (email/username) | `POST /auth/login` | none, `login` limiter, adaptive CAPTCHA | — | — | identifier, password | messages |
| | Issue public mobile token | `POST /auth/token` | none, `login` limiter | — | — | identifier, password, `device_name` | messages |
| | Issue admin mobile token | `POST /admin/auth/token` | none, `login` limiter | account must be admin | — | as above | messages |
| | Logout (current) / list devices / revoke one / revoke all | `POST /auth/logout`, `GET /auth/devices`, `DELETE /auth/devices/{id}`, `DELETE /auth/devices` | sanctum | own only | — | id belongs to user | messages |
| | Current user | `GET /auth/me` | sanctum | — | — | — | role and Workspace labels |
| | Password reset request / reset | `POST /auth/password/forgot`, `POST /auth/password/reset` | none, limiter, CAPTCHA | — | — | email; token + password | messages, email |
| | Email verification | `POST /auth/email/verification-notification`, `GET /auth/email/verify/{id}/{hash}` (signed) | sanctum / signed | — | — | signature | email |
| | OTP send / verify *(phone phase)* | `POST /auth/otp`, `POST /auth/otp/verify` | none or sanctum, limiter, CAPTCHA | — | — | E.164, purpose, code | SMS text |
| | Social login / link / unlink *(social phase)* | `POST /auth/social/{provider}`, `POST /auth/identities/{provider}`, `DELETE /auth/identities/{provider}` | none / sanctum | — | — | provider allow-list, ID-token verification | messages |
| **Users (self)** | View / update own profile | `GET /me/profile`, `PATCH /me/profile` | sanctum | — | — | username, name, bio, social_links, locale | messages |
| | Change password / email / phone | `PUT /me/password`, `PUT /me/email`, `PUT /me/phone` | sanctum (+ current password) | — | — | strength; re-verification | messages |
| | Avatar *(media phase)* | `PUT /me/avatar` | sanctum | — | — | image type and size | messages |
| | Public profile | `GET /users/{username}` | optional | — | — | — | — |
| **Users (admin, workspace `users`)** | List / show | `GET /admin/users/users`, `GET /admin/users/users/{id}` | admin gate | `users.view` | — | filters allow-list | messages |
| | Create / update | `POST`, `PATCH /admin/users/users/{id}` | admin gate | `users.create` / `users.update` | — | no `account_type` or status fields | messages |
| | Delete / restore / force-delete | `DELETE …/{id}`, `POST …/{id}/restore`, `DELETE …/{id}/force` | admin gate | `users.delete` / `.restore` / `.force-delete` | — | last-admin, not self | messages |
| | Activate / deactivate / ban / unban | `POST …/{id}/activate`, `/deactivate`, `/ban`, `/unban` | admin gate | `users.activate` / `.deactivate` / `.ban` / `.unban` | — | reason, until; not self; last-admin | messages |
| | Change account type | `PUT …/{id}/account-type` | admin gate + password confirmation | `users.change-account-type` | — | enum; not self; last-admin | messages |
| | Revoke sessions and tokens | `DELETE …/{id}/sessions` | admin gate | `users.revoke-sessions` | — | — | messages |
| | Assign roles / direct permissions | `PUT …/{id}/roles`, `PUT …/{id}/permissions` | admin gate (+ password confirmation if sensitive) | `roles.assign` / `permissions.assign-direct` | — | grant ceiling; role scope matches account type; not self | messages |
| **AccessControl (workspace `users`)** | List permissions (grouped) | `GET /admin/users/permissions` | admin gate | `permissions.view` | — | — | display names and descriptions; `?include=translations` |
| | Role CRUD | `GET/POST/PATCH/DELETE /admin/users/roles[/{id}]` | admin gate | `roles.view` / `.create` / `.update` / `.delete` | — | `{ar,en}` required; protected roles immutable | translated labels |
| | Sync role permissions | `PUT /admin/users/roles/{id}/permissions` | admin gate + password confirmation (if sensitive) | `roles.assign-permissions` | — | grant ceiling | messages |
| | Permission-group and Workspace labels | `PATCH /admin/users/permission-groups/{key}`, `PATCH /admin/users/workspaces/{key}` | admin gate | `roles.update` (labels only) or a dedicated permission (open) | — | `{ar,en}` | translated |
| **Content (workspace `content`)** | Category CRUD | `/admin/content/categories[...]` | admin gate | `categories.*` | parent within scope | name `{ar,en}`, slug | translated names |
| | Assign users to categories | `PUT /admin/content/categories/{id}/users` | admin gate | `categories.assign-users` | actor's own scope (grant ceiling) | user IDs exist and are eligible | messages |
| | News CRUD / publish / restore / force-delete / bulk | `/admin/content/news[...]` | admin gate | `news.*` | **yes**: 404 out of scope; 422 for a disallowed target category | category in scope; status transitions | messages |
| | Lookups (authors, categories) | `GET /admin/content/lookups/authors` | admin gate | `news.view` | — | minimal fields only | — |
| | Writer: own news | `/news/mine[...]` | sanctum, `account_type=writer` | writer-role permissions | yes (Q: ADR-0005 Q4) | ownership | messages |
| | Public news | `GET /news`, `GET /news/{slug}`, `GET /categories` | optional | — | published only | filter allow-list | content language (ADR-0007 Q2) |
| **Media (workspace `media`; later phase)** | Upload / list / delete | `POST/GET/DELETE /admin/media/media[...]` | admin gate | `media.upload` / `.view` / `.delete` | — | MIME allow-list, size, dimensions | alt text `{ar,en}` |
| **Audit (workspace `audit`)** | Query log | `GET /admin/audit/logs` | admin gate | `audit.view` | — | filter allow-list, date range | event labels |
| **Settings (workspace `settings`; later)** | View / update | `GET/PATCH /admin/settings/...` | admin gate | `settings.view` / `.update` | — | per setting | labels |
| **Notifications** | *Pending the full update brief* | — | — | — | — | — | — |
