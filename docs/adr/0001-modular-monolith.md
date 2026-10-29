# ADR-0001: Modular monolith architecture

- **Status:** Proposed
- **Date:** 2026-10-29
- **Deciders:** Product owner (pending), backend lead
- **Priority:** Foundational. It must be decided before any feature code.

## Context

- **[Fact]** `api/src` is an unmodified Laravel 13.39.0 skeleton running on PHP 8.5.12 (Docker image `alphamaster-api`) against MySQL 8.4.11 (`alphamaster-db`). It contains only `App\Models\User`, `App\Http\Controllers\Controller`, and `App\Providers\AppServiceProvider`, plus `routes/web.php` and `routes/console.php`. There is no `routes/api.php`.
- **[Fact]** There is no existing module structure, so nothing has to be migrated.
- One backend serves four clients: React admin, admin mobile, public mobile, and the public website.
- The expected business areas are identity, users, access control, media, categories/articles, settings, notifications, and audit. They are tightly related, for example through the category-scoped permissions in [ADR-0005](0005-category-level-authorization.md).
- **[Assumption]** A small team deploys a single backend. No requirement calls for independent scaling or independent release cycles per area.

## Problem statement

How should the backend code be organized so that business areas stay separate, keep clear ownership, and avoid circular dependencies, without the operational cost of distributed services?

## Decision

**[Recommendation]** Adopt a **modular monolith**: one Laravel application, one database, and one deployable image, with the code split into **modules under `app/Modules/<Module>`** in the existing `App\` PSR-4 namespace. Each module is registered by its own service provider. Conventional MVC is used *inside* a module.

### Module list

| Module | Owns | May depend on |
|---|---|---|
| **Core** (shared kernel, not a business module) | API response/error contract, error-code catalog, locale negotiation, the translatable-JSON cast, the `ModuleServiceProvider` base, the `HumanVerifier` contract, the rate-limit definitions, and base test helpers | Laravel only |
| **Identity** | Login (password, OTP, social), Sanctum sessions and tokens, password reset, email/phone verification, linked social identities, client-type enforcement | Core, Users (through its public contract) |
| **Users** | The `users` record, profile fields, account classification, active/banned status, the "last admin" invariant, admin user-management APIs | Core, AccessControl (through contracts), Media (through contracts) |
| **AccessControl** | Spatie roles and permissions, permission groups, translated metadata, the permission catalog registry, role/permission assignment APIs | Core |
| **Content** | Categories, articles, category-to-user scope assignments, the category-scope policy | Core, AccessControl, Users, Media |
| **Media** (when that phase starts) | The central media assets, storage/CDN abstraction, image conversions, attachment references | Core |
| **Audit** | The append-only security/audit log and its query API | Core |
| **Settings**, **Notifications** | Deferred. Each is created only when its first real requirement arrives. | Core |

Roles and Permissions are **one module** (AccessControl), because they share tables, the Spatie package, and the metadata model. Categories and Articles start as **one module** (Content), because category scope exists to protect articles. Content can be split later if Categories gets other consumers.

### Dependency direction

- Dependencies point **towards Core**, and none of them is circular.
- One module calls another only through that module's **public surface**: its `Contracts/` interfaces (bound in its provider), public application actions, and published domain events.
- Controllers, Form Requests, Eloquent models (for writes), and internal services are **module-private**.
- Reading another module's Eloquent model is allowed for relationships, for example `Article::category()`. **Writes** must go through the owning module's actions.
- Cross-module side effects use **domain events**, for example `Users\Events\UserBanned` leads Identity to revoke tokens and Audit to record the event.

### Module anatomy (only the parts a module needs)

```
app/Modules/Users/
├── UsersServiceProvider.php        # extends Core\Support\ModuleServiceProvider
├── Contracts/                      # public interfaces (e.g. UserDirectory)
├── Actions/                        # application use cases (BanUser, ChangeAccountType)
├── Models/  Enums/  Events/  Listeners/  Policies/
├── Http/Controllers/  Http/Requests/  Http/Resources/  Http/Middleware/
├── Routes/api.php                  # loaded under /api/v1 by the provider
├── Database/Migrations/  Database/Seeders/  Database/Factories/
├── Permissions.php                 # BackedEnum catalog + metadata (ADR-0004)
├── Lang/{ar,en}/                   # module translations, namespace "users::"
└── Tests/{Feature,Unit}/
```

### Registration and lifecycle

- Module providers are listed explicitly in `bootstrap/providers.php` (Laravel 11+ convention), in dependency order. There is **no automatic discovery**, so enabling a module is a visible, reviewable code change.
- `ModuleServiceProvider` (Core) offers helpers that each module calls:
  - `loadMigrationsFrom()` and `loadTranslationsFrom(..., '<module>')`
  - route registration under the `api/v1` prefix with the module's middleware
  - policy registration
  - event-listener registration
  - **permission catalog registration**: the module declares its permissions to the AccessControl registry. Declaring a permission **never grants it to anyone**.
- **Disabling a module:**
  - Remove its provider. Its routes, listeners, and policies disappear.
  - Its tables and data stay.
  - Its permissions stay in the database but are marked `is_deprecated` by the `access:sync-permissions` command ([ADR-0004](0004-authorization-with-spatie-permission.md)), and the admin UI hides them.
  - Existing role assignments stay, so re-enabling the module is lossless.
- **Removing a module permanently** is a separate, reviewed change. It includes a migration that drops the module's tables and an explicit cleanup command for its permissions. Nothing is deleted implicitly.

### Migrations and seeding

- Migrations live in each module's `Database/Migrations` and are discovered through `loadMigrationsFrom`. Laravel orders them **globally by timestamp prefix**, so a module that depends on another module's table must have a later timestamp. Code review enforces this, and a CI test runs `migrate:fresh` on MySQL ([ADR-0011](0011-dependencies-testing-and-ci.md)).
- Foreign keys may only point "down" the dependency graph (Content → Users is allowed; Users → Content is not).
- The package migrations (Sanctum `personal_access_tokens`, Spatie permission tables) are published **into the owning module** (Identity and AccessControl respectively), so each table has one clear owner.
- Seeders are split into two kinds:
  - **Reference seeders** (permission catalog, groups, protected roles) are idempotent, safe in production, and run on every deploy through `access:sync-permissions`.
  - **Demo seeders** (fake users and content) never run in production.
- Every migration must have a working `down()`, or be explicitly marked irreversible in its docblock with the reason (for example, data-destructive changes).

## Alternatives considered

| Option | Verdict |
|---|---|
| **Conventional Laravel layout** (`app/Models`, `app/Http/Controllers` for everything) | Simple at first, but ownership blurs once there are 5+ areas, and permission/route registration gets scattered. Rejected as the overall structure; still used *inside* modules. |
| **`nwidart/laravel-modules` v13.3.0** | [Fact] Compatible (PHP ^8.3). It is mature, but adds `wikimedia/composer-merge-plugin`, a per-module `composer.json`/`module.json`, its own generators, and its own enable/disable state files. That is more machinery than one team and one deployable need. Rejected for now, but stays the fallback if modules ever need separate Composer dependencies. |
| **`internachi/modular` 3.0.2** | [Fact] Compatible with Laravel 13 and lighter (modules as local Composer path packages, auto-discovered). Each module needs a separate `composer.json` and Composer path repositories. A reasonable alternative; not chosen because plain namespaces already give the same result with zero dependencies. |
| **Package-based modules** (one Composer package per module, in separate repositories) | Versioning overhead with no benefit for a single application. Rejected. |
| **Microservices** | No requirement for independent scaling or deployment. It would add network failure modes, distributed auth, and data consistency problems. Rejected. |

## Reasons and trade-offs

- No new dependency; uses only standard Laravel mechanisms (service providers, `loadMigrationsFrom`, `loadTranslationsFrom`).
- Boundaries are enforced by **convention plus an automated architecture test** ([ADR-0011](0011-dependencies-testing-and-ci.md)), not by Composer isolation. This is weaker than separate packages, but cheap and adequate.
- Artisan generators (`make:model` and similar) target `app/` by default, so developers pass explicit paths or namespaces. A small Core command `make:module` can scaffold a module skeleton.

## Database implications

- One schema. Table names are unprefixed but owned by exactly one module (owners are listed in [data model](../architecture/03-data-model.md)).
- Foreign keys may cross modules only along the allowed dependency direction.

## Security implications

- Central registration lets Core apply the same middleware stack (auth, account status, locale, throttling) to every module's routes, which avoids unprotected routes.
- Explicit provider registration means no code path is activated by a file merely appearing on disk.

## Performance implications

- No runtime overhead beyond loading the providers. `route:cache`, `config:cache`, and `event:cache` still work, and should run at image build or container start.

## Migration implications

- The existing skeleton tables (`users`, `password_reset_tokens`, `sessions`, `cache`, `jobs`) stay where they are. The `users` migration is adopted by the Users module; its file is moved there only when it first needs changing, during the implementation phase.

## Testing requirements

- Module tests live in `app/Modules/*/Tests` and are added to `phpunit.xml` test suites.
- An architecture test checks that no module imports another module's non-public namespaces (`Http`, `Models` for writes, internal `Services`).
- `migrate:fresh --seed` on MySQL runs in CI to prove the cross-module migration order works.

## Consequences

- Every new business area starts as a module with a clear owner.
- Some boilerplate per module (provider, routes file, lang folders) is accepted.
- Extracting a module to a separate service later stays possible, because the communication paths are already contracts and events.

## Open questions

1. Should Settings and Notifications be in phase 1, or wait for concrete requirements? *Recommended default: wait.*
2. Will any module ever need its own Composer dependencies that others must not see? If yes, revisit `internachi/modular`.

## Related ADRs and references

- [ADR-0004](0004-authorization-with-spatie-permission.md) (permission registration), [ADR-0006](0006-api-contract-and-versioning.md) (routes), [ADR-0011](0011-dependencies-testing-and-ci.md) (architecture tests)
- [Module map and directory structure](../architecture/02-modules-and-structure.md)
