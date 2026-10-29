# ADR-0007: Arabic and English localization

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Foundational

## Context

- **[Fact]** `config/app.php` has `locale = env('APP_LOCALE','en')` and `fallback_locale = env('APP_FALLBACK_LOCALE','en')`. `.env.example` sets `APP_LOCALE=en`.
- **[Fact]** There is **no `lang/` directory** (Laravel 13 doesn't publish language files by default) and no translation package.
- **[Fact]** Laravel ships only English language files. **Arabic validation and auth messages must be supplied**, either written by the team or taken from a community package.
- **[Fact]** The runtime image has the `intl` extension (needed for locale-aware formatting and for phone libraries).
- Arabic and English are mandatory from day one. More languages may come later.

## Problem statement

How is the request locale chosen, where do static and dynamic translations live, and how do clients get localized messages without letting the locale affect behavior?

## Decision

### 1. Locale negotiation

- Core middleware `SetLocaleFromRequest` runs on all API routes. It reads the standard **`Accept-Language`** header and picks the best match from the configured `app.supported_locales = ['ar','en']` (q-values respected; `ar-SA` → `ar`).
- **Missing or unsupported values** fall back to the **default locale**, and the request does not fail. A bad header must not break a mobile app.
- The response always includes `Content-Language: ar|en` and `Vary: Accept-Language`.
- An authenticated user's stored `preferred_locale` (new nullable `users` column) is used **only for out-of-band messages** (emails, push notifications, SMS). The request header always wins for API responses.
- **The locale never changes authorization, validation rules, or error codes** ([ADR-0006](0006-api-contract-and-versioning.md)). A test proves that identical requests in `ar` and `en` produce identical status codes and `code` values.
- Default locale: product decision (Q1). The current config value is `en`, and it stays so until decided.

### 2. Static strings: Laravel language files

- `lang/{ar,en}/` (published with `php artisan lang:publish`) holds the framework's `validation.php`, `auth.php`, `passwords.php`, and `pagination.php`, plus Core's `errors.php`. Error titles are keyed by `ErrorCode`, for example `errors.VALIDATION_ERROR`.
- Each module keeps its own `Lang/{ar,en}/*.php`, loaded with the `<module>::` namespace ([ADR-0001](0001-modular-monolith.md)).
- **Arabic validation messages:**
  - **[Recommendation]** The team owns `lang/ar/validation.php`, starting from a reviewed community translation (for example the `laravel-lang` project). The source is copied once and reviewed by a native speaker, rather than tracked as a runtime dependency.
  - Arabic attribute names go in `validation.attributes`.
- **Missing translations:** fall back to `en`, and log a warning (`Lang::handleMissingKeysUsing`) in non-production environments. A CI test fails if any key exists in one locale but not the other (a key-parity test).

### 3. Dynamic metadata: JSON columns

- Database-managed labels (permission and group names and descriptions, role names, category names, and later settings labels) use **JSON columns `{"ar": "...", "en": "..."}`**.
- They are read through a Core cast `TranslatableJson`, which:
  - returns the current-locale value with fallback to `en`, then to the first non-empty value,
  - exposes `->translations()` for admin editing,
  - validates writes with the rule `translatable:required` (requires every supported locale and rejects unknown keys).
- **[Recommendation]** Use a small Core cast instead of `spatie/laravel-translatable` 6.15.0.
  - **[Fact]** The package is compatible with Laravel 13 but requires PHP ^8.4. The runtime is 8.5.12, but `composer.json` declares `php ^8.3`, so adopting it means raising that constraint.
  - The package's extra value (query helpers such as `whereJsonContainsLocale`) isn't needed for ~4 metadata tables.
  - Adopt the package later, without changing the data format, if translated *content* (articles in two languages) arrives. Both use the same JSON shape.
- **Article content translation** (is an article written in both languages, or one language per article?) is a separate content-model decision (Q2). This ADR covers UI and metadata only.

### 4. API serialization

- Default: the localized string, e.g. `"display_name": "حذف المستخدم نهائياً"` when `Accept-Language: ar`.
- Admin editing screens request `?include=translations`, which adds the full map: `"display_name_translations": {"ar": "...", "en": "..."}`.
- Stable keys (`name`, `key`, `code`) are always present, so clients never key logic on labels.
- Dates and numbers are sent in machine format (ISO 8601, plain numbers). Formatting with Arabic-Indic digits, Hijri display, and similar is the client's job, so the API doesn't constrain RTL interfaces.

### 5. Extending to a new language

1. Add the locale to `supported_locales`.
2. Add `lang/<xx>/` files.
3. Backfill the new key in the JSON columns with a data migration or the admin UI.

The key-parity test and the `translatable:required` rule tell you exactly what is missing. No schema change is needed.

## Alternatives considered

| Option | Verdict |
|---|---|
| Locale in the URL (`/api/v1/ar/...`) | Pollutes every route and duplicates caches. Rejected for the API (fine for the public website's pages). |
| Custom `X-Locale` header | Non-standard. `Accept-Language` is what HTTP clients already send. Rejected. |
| A translations table per entity, or a generic `translations` table | Normalized, but more joins. Acceptable later if many languages arrive ([ADR-0004](0004-authorization-with-spatie-permission.md)). |
| Translation SaaS or DB-driven UI strings (e.g. `spatie/laravel-translation-loader`) | Useful if non-developers must edit UI strings live. Not required now. Deferred. |

## Reasons and trade-offs

Standard HTTP negotiation, Laravel's native files for static text, and JSON columns for dynamic metadata are the smallest design that covers both languages and scales to more. The cost is maintaining an Arabic `validation.php`, which is unavoidable with any approach.

## Database implications

- JSON columns on the metadata tables. MySQL 8.4 JSON is validated and can be indexed through generated columns if ever needed; it isn't needed now.
- `users.preferred_locale` (`VARCHAR(8)`, nullable).
- **[Fact]** SQLite, used in tests, stores JSON as text. Running tests on MySQL in CI covers JSON behavior ([ADR-0011](0011-dependencies-testing-and-ci.md)).

## Security implications

- Translated strings are output-escaped by clients.
- Locale values are validated against the allow-list, so they can't be used for path traversal into `lang/`.
- Translations must never contain sensitive data.

## Performance implications

- Language files are loaded and cached per request (negligible).
- JSON decoding happens per row on the metadata tables (small).
- `Vary: Accept-Language` must be respected by any HTTP or CDN cache.

## Migration implications

- `lang:publish` adds files under `lang/`. That is a code change, made in the implementation phase.
- The JSON columns are added in their owning modules' migrations.

## Testing requirements

- `Accept-Language` parsing: `ar`, `en`, `ar-SA`, `fr` (default), empty (default), garbage (default).
- Validation messages in both languages for a sample of rules.
- Every `ErrorCode` has a title in both languages.
- Key-parity test between `lang/ar` and `lang/en` (for Core and every module).
- Metadata writes without both locales are rejected.
- Authorization results are identical across locales.

## Consequences

- Every new user-facing string needs both translations in the same pull request; CI enforces this.
- A native Arabic reviewer is needed for the initial validation file.

## Open questions

1. **Q1.** Is the default locale (used when the header is missing) Arabic or English? *Recommended default: Arabic, if the primary audience is Arabic-speaking.*
2. **Q2.** Is article *content* bilingual (each article has `ar` and `en` versions) or one language per article? This affects the Content schema.
3. **Q3.** Are more languages expected within 12 months? This decides JSON columns versus translation tables.

## Related ADRs and references

[ADR-0004](0004-authorization-with-spatie-permission.md), [ADR-0006](0006-api-contract-and-versioning.md), [ADR-0011](0011-dependencies-testing-and-ci.md); Laravel 13 localization documentation; RFC 9110 §12.5.4 (`Accept-Language`)
