# ADR-0011: Dependency strategy, testing, and continuous integration

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Foundational

## Context

- **[Fact]** `composer.json` declares:
  - `require`: `php ^8.3`, `laravel/framework ^13.17` (locked at **13.39.0**), `laravel/tinker ^3.0`
  - `require-dev`: `fakerphp/faker`, `laravel/pail`, `laravel/pao`, `laravel/pint`, `mockery/mockery`, `nunomaduro/collision`, `phpunit/phpunit ^12.5.12`
- **[Fact]** The runtime is **PHP 8.5.12** (`php:8.5-apache`). The declared floor `^8.3` is lower than the runtime, so packages requiring PHP ^8.4 (`spatie/laravel-translatable`, `spatie/laravel-activitylog`) would need the floor raised.
- **[Fact]** Tests: PHPUnit 12, two example tests. `phpunit.xml` runs on **SQLite `:memory:`**, while production uses **MySQL 8.4.11**.
- **[Fact]** CI exists (`.github/workflows/ci.yml`) with two jobs:
  - **Laravel:** PHP 8.5 via `setup-php`; `composer audit`; Pint; `php artisan test`
  - **Docker:** build; `compose up --wait`; migrate; smoke tests; security assertions (disabled functions, no `php-cgi`, non-root, no `X-Powered-By`, PHP ≥ 8.5)
- **[Fact]** The result of the first CI run on GitHub could not be confirmed, because the repository is private and no GitHub CLI session was available.
- **[Fact]** Branch protection status is unknown.

## Problem statement

How do we add dependencies safely, and what testing and CI gates must exist before and during implementation?

## Decision

### 1. Dependency policy

- **Prefer built-in Laravel** features. A package is added only when it replaces substantial, security-sensitive, or well-solved code.
- **Every new dependency** needs, in its pull request:
  - its purpose
  - compatibility checked against the locked Laravel/PHP versions
  - its maintenance status
  - the alternatives considered
  - `composer audit` clean
- Updates go through Dependabot/Renovate pull requests (recommended) that pass CI.
- **Raise the `composer.json` PHP floor to `^8.5`** to match the runtime image. This also enables packages with a PHP ^8.4 floor. It is a phase-1 configuration change, made only after approval (Q1).
- **Pin pre-1.0 packages** (Scramble) to a minor version.

The full register (installed / recommended / optional / unnecessary / needs approval) is in [04-package-register](../architecture/04-package-register.md).

### 2. Test strategy

| Layer | Scope | Database |
|---|---|---|
| **Unit** | Enums, casts (`TranslatableJson`), the identifier parser, social-link validator, error-code mapping, the grant-ceiling calculation | None |
| **Feature (API)** | Every endpoint: success shape, auth (401), admin gate (403), permission (403), category scope (404/422), validation (422), locale (`ar` and `en`), rate limit (429), audit row written | **MySQL 8.4** |
| **Contract** | Responses validated against `openapi.json` ([ADR-0010](0010-frontend-contract-openapi-and-zod.md)) | MySQL |
| **Architecture** | Module boundaries ([ADR-0001](0001-modular-monolith.md)); no `Gate::before`; controllers return Resources; `$fillable` excludes protected columns | None |
| **Security regression** | Privilege escalation (self-role change, grant ceiling, account-type injection), token reuse after ban, IDOR across categories, no secrets in audit context, no stack traces when `APP_DEBUG=false` | MySQL |
| **Migration** | `migrate:fresh --seed`, then `migrate:rollback` across all modules; `access:sync-permissions` idempotency | MySQL |
| **Smoke / infrastructure** | The existing Docker job | MySQL (container) |

- **Why MySQL for feature tests?** SQLite differs on the points this design depends on:
  - case-insensitive unique emails and usernames (collation)
  - JSON functions
  - foreign-key enforcement details
  - `SELECT … FOR UPDATE` (the last-admin invariant)

  SQLite may stay for fast unit runs. The **required** CI feature suite runs against a MySQL 8.4 service container.
- **Test data:** factories per module (`UserFactory` gets states: `admin()`, `writer()`, `banned()`, `inactive()`, `withRole()`, `withCategories()`).
- **Concurrency tests** for the last-admin invariant use two DB connections.

### 3. CI evolution (to implement in phase 1, after approval)

Keep the current jobs, and:

1. Add a **MySQL 8.4 service** to the Laravel job. Run the feature, contract, and migration suites against it.
2. Add **coverage** reporting (the PCOV extension) with a modest initial threshold on the Core, Identity, Users, and AccessControl modules, raised over time.
3. Add an **OpenAPI drift check** ([ADR-0010](0010-frontend-contract-openapi-and-zod.md)).
4. Add a **lang key-parity check** ([ADR-0007](0007-arabic-english-localization.md)).
5. Optionally add **static analysis**: Larastan (PHPStan for Laravel) at a moderate level. *[Not verified: Larastan's compatibility with Laravel 13 was not checked; check it before adoption.]*
6. Optionally add a **container image scan** (e.g. Trivy) in the Docker job.

**Merge gating (GitHub settings, owner action):**

- Protect `main`.
- Require pull requests.
- Require the status checks `Laravel tests (PHP 8.5)` and `Docker build & smoke test` (plus new required jobs as they are added).
- Require the branch to be up to date before merging, and disallow force pushes.

**Branch protection is a repository setting, not a file, so the owner must enable it.**

## Alternatives considered

- **Pest instead of PHPUnit:** a nicer syntax and built-in architecture testing (`arch()`). PHPUnit 12 is already installed, and architecture rules can be written as PHPUnit tests or with a small reflection helper. Switching is optional; the team decides (Q2).
- **SQLite-only tests:** faster, but hide the MySQL-specific behaviors listed above. Rejected for the required suites.
- **Deptrac for module boundaries:** more powerful layer rules, but another tool. Start with PHPUnit architecture tests; adopt Deptrac if the boundaries grow complex.

## Reasons and trade-offs

MySQL-backed CI is slower (adds about 30–60 s), but tests the database the product actually runs on. The dependency policy trades some speed of adding features for supply-chain safety.

## Database implications

The CI MySQL service is ephemeral. Tests use `RefreshDatabase` with transactions where possible.

## Security implications

CI is the automated enforcement point for the security rules in ADRs 0002–0009. Required checks plus branch protection prevent merging regressions.

## Performance implications

Parallel testing (`php artisan test --parallel`) can be enabled when the suite grows. It needs per-process databases, which Laravel supports.

## Migration implications

None now. The CI changes are phase-1 work.

## Testing requirements

This ADR defines them.

## Consequences

- Phase 1 includes test infrastructure work (factories, MySQL CI, helpers) before features.
- The owner must configure branch protection.

## Open questions

1. **Q1.** Approve raising the `composer.json` PHP constraint to `^8.5` (matching the runtime)?
2. **Q2.** PHPUnit (current) or switch to Pest?
3. **Q3.** Should the GitHub CLI be authenticated so CI results can be monitored from this environment, or will the owner check the Actions page?

## Related ADRs and references

[ADR-0001](0001-modular-monolith.md), [ADR-0007](0007-arabic-english-localization.md), [ADR-0010](0010-frontend-contract-openapi-and-zod.md), and the existing `.github/workflows/ci.yml`
