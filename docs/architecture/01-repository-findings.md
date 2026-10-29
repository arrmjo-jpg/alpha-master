# A. Repository findings (verified 2026-10-29)

These findings come from inspecting `F:\Alpha-Master` and the running containers. "None" means verified absent.

## Versions

| Item | Verified value | Source |
|---|---|---|
| Laravel | **13.39.0** (`^13.17` in composer.json) | `composer.lock`, `php artisan --version` |
| PHP (runtime) | **8.5.12** | `php -v` in `AlphaMaster-API` |
| PHP (declared floor) | `^8.3` | `composer.json`. **This is lower than the runtime** (ADR-0011 Q1). |
| Database (runtime) | **MySQL 8.4.11** Community | `mysql --version` in `AlphaMaster-DB` |
| Database (tests and `.env.example`) | SQLite (`:memory:` in `phpunit.xml`; `DB_CONNECTION=sqlite` in `api/src/.env.example`) | Mismatch with production (ADR-0011) |
| Web server | Apache 2.4 + mod_php (`php:8.5-apache`), port 8080 | `api/Dockerfile` |
| PHPUnit | ^12.5.12 | `composer.json` |

## Installed packages

- **`require`:** `laravel/framework`, `laravel/tinker`.
- **`require-dev`:** `fakerphp/faker`, `laravel/pail`, `laravel/pao`, `laravel/pint`, `mockery/mockery`, `nunomaduro/collision`, `phpunit/phpunit`.
- **Not installed:** Sanctum, Spatie Permission, Socialite, any translation, media, audit, OpenAPI, module, CAPTCHA, or phone library.

## Schema (migrations present)

- **`users`:** `id`, `name`, `email` (unique), `email_verified_at`, `password`, `remember_token`, `timestamps`.
  - **No** `is_writer`, `username`, `phone`, `bio`, avatar, social links, status, account type, or soft deletes.
- **`password_reset_tokens`, `sessions`** (from the same migration file).
- **`cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`.**
- **None:** roles, permissions, categories, articles, media, audit, tokens.

## Application code

- `App\Models\User`: `HasFactory`, `Notifiable`; uses the Laravel 13 PHP attributes `#[Fillable(['name', 'email', 'password'])]` and `#[Hidden(['password', 'remember_token'])]`; casts `email_verified_at` and `password => hashed`. *(Corrected 2026-10-29: an earlier version of this file wrongly said no fillable list was declared.)*
- `App\Http\Controllers\Controller` (empty base), `App\Providers\AppServiceProvider` (empty).
- Routes:
  - `web.php`: `GET /` returns the welcome view.
  - `console.php`.
  - The health route `/up`.
  - **No `api.php`**, and no API routes.
- `bootstrap/app.php` renders JSON for `api/*` or `expectsJson()` exceptions. There is no custom middleware.
- Seeders: `DatabaseSeeder` creates one `test@example.com` user (demo only).
- Tests: `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php`.

## Configuration

| Area | Verified state |
|---|---|
| Auth | One `web` session guard, `users` Eloquent provider |
| Session | `database` driver, lifetime 120, `SESSION_DOMAIN=null`, `same_site=lax`, `secure` from env (unset) |
| Locale | `APP_LOCALE=en`, `APP_FALLBACK_LOCALE=en`. **No `lang/` directory.** No translation package. |
| Cache / Queue | `database` / `database`. **There is no queue-worker or scheduler container.** |
| Filesystem | `local` disk. `storage/app` is a named volume in Docker. No S3/CDN. |
| Mail | `MAIL_MAILER=log`. PHP `mail()` is **disabled**, so production needs SMTP or an API mailer. |

## Infrastructure and security (already in place)

**Docker images and Compose:**

- Images `alphamaster-api` and `alphamaster-db`; containers `AlphaMaster-API` and `AlphaMaster-DB`.
- Compose is split into `docker-compose.yml`, `.data`, `.backend`, `.ports`, and `.override`.

**PHP hardening (`api/php/security.ini`):**

- `disable_functions` = `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen`, `pcntl_*`, `dl`, `putenv`, `mail`, `link`, `symlink`, `mb_send_mail`, `imap_mail`.
- Plus: `open_basedir=/var/www/html:/tmp`, `allow_url_fopen/include=Off`, `expose_php=Off`, `variables_order=EGPCS`.

**Apache:**

- No CGI modules, no `php-cgi` binary.
- Only `index.php` executes.
- Dotfiles are denied.
- `ServerTokens Prod`.
- `nosniff`, `DENY` framing, and `no-referrer` headers.

**Container:**

- Non-root (`www-data`), read-only rootfs, `cap_drop: ALL`, `no-new-privileges`.
- The DB is not published to the host.

**PHP extensions:**

- Present: `pdo_mysql`, `bcmath`, `intl`, `zip`, `curl`, `fileinfo`, `sodium`.
- **Absent:** `gd`, `imagick`, `exif`, `redis`.

## CI and repository

- **Git:** remote `origin`, `main` branch, 2 commits. The working tree was clean before this documentation task.
- **CI:** `.github/workflows/ci.yml` has two jobs:
  - **Laravel:** setup-php 8.5, `composer audit`, Pint, tests
  - **Docker:** build, `up --wait`, migrate, smoke and security checks
  - **The first run's result was not visible:** the repository is private and no `gh` session was available.
- **Branch protection:** unknown.

## Clients

- **No React, TypeScript, or mobile code** in this repository. `package.json` has only Vite, Tailwind, and the Laravel Vite plugin.
- No documentation about mobile clients exists.

## Not found (and therefore not assumed)

- Existing ADRs or architecture docs.
- `is_writer` or any account classification.
- Category, content, or media schemas.
- Audit logging.
- API versioning or a response convention.
- Module organization.
