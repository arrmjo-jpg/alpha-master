# ADR-0006: API-first architecture, response/error contract, and versioning

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Foundational

## Context

- **[Fact]** No API routes exist (`routes/api.php` is absent, and `install:api` has not been run).
- **[Fact]** `bootstrap/app.php` already sets `shouldRenderJsonWhen(fn ($r) => $r->is('api/*') || $r->expectsJson())`, so exceptions under `/api/*` already render as JSON. The shape is Laravel's default (`{message, errors}`), which is not yet a defined contract.
- **[Fact]** Production runs with `APP_DEBUG=false`, so stack traces are not rendered. The dev override sets `APP_DEBUG=true`.
- Four clients, including mobile apps that cannot be force-updated instantly, need one stable contract.

## Problem statement

What is the single response format for successes and failures, how are errors identified across languages, and how does the API evolve without breaking older mobile clients?

## Decision

### 1. Every business operation is an API operation

- Controllers are thin. They validate through a **Form Request**, authorize through a **policy** or permission middleware, call a module **Action**, and return an **API Resource**.
- No business logic lives only in a web view or only in a client.
- Admin and public endpoints share the same Actions, but are **separate routes** with separate Resources. Admin Resources may expose internal fields; public Resources never do.

### 2. Route layout

```
/api/v1/auth/...          Identity (login, logout, me, devices, OTP, social, password reset)
/api/v1/admin/<workspace>/... Admin environment (ADR-0002 gate + ADR-0012 workspace + permissions)
/api/v1/<module>/...      Public and writer endpoints (published content, own profile, own articles)
```

Each module registers its own routes through its provider ([ADR-0001](0001-modular-monolith.md)).

### 3. Response contract: plain resources for success, RFC 9457 for errors

**[Recommendation]** Use the HTTP status as the success/failure signal. Don't add a redundant `success` flag.

**Success** (Laravel API Resource shape):

```json
{ "data": { "id": 123, "username": "sara" }, "meta": {} }
```

**Collections** use cursor pagination by default. Offset pagination is allowed for admin tables that need page numbers.

```json
{ "data": [ ... ], "meta": { "per_page": 25, "next_cursor": "eyJ...", "prev_cursor": null }, "links": { "next": "...", "prev": null } }
```

Successful mutations can include a localized `meta.message` for toasts. Clients must not parse it.

**Errors** use **RFC 9457 Problem Details** (`Content-Type: application/problem+json`) with two extension members, `code` and `errors`:

```json
{
  "type": "https://api.alphamaster.example/problems/validation-error",
  "title": "The submitted data is invalid.",
  "status": 422,
  "code": "VALIDATION_ERROR",
  "detail": "The email field must be a valid email address.",
  "errors": { "email": [ { "code": "email", "message": "The email field must be a valid email address." } ] },
  "trace_id": "01JB..."
}
```

- `title`, `detail`, and the `message` values are **localized** ([ADR-0007](0007-arabic-english-localization.md)).
- `code`, the field keys, and the per-field rule `code` are **stable, uppercase or snake identifiers** that never change with the locale.
- `trace_id` is a request ID echoed in the logs and in the `X-Request-Id` response header, for support.
- In production, only these fields are returned. There are no exception class names, SQL, file paths, stack traces, tokens, or input echoes of secret fields. The unexpected-error handler logs the full exception server-side under `trace_id` and returns a generic 500.
- One Core exception renderer, registered in `withExceptions()`, maps exceptions to problems. Modules throw typed `DomainException` subclasses carrying a code, never ad-hoc JSON.

**Why RFC 9457 rather than a custom `{success, message, data, error}` envelope?**

- It is an IETF standard that generic HTTP tooling (OpenAPI 3.1 schemas, client libraries, API gateways) understands.
- Success bodies stay compatible with Laravel API Resources without wrappers.
- It still carries everything the brief asks for: a localized message, a stable code, and field details.
- The custom envelope is a valid alternative. It is marginally easier for beginners to read, but it duplicates the HTTP status in the body and is non-standard. **If the frontend and mobile teams prefer the envelope, this is an easy switch now, and expensive later, so decide before phase 1 (Q1).**

### 4. Stable error-code catalog (initial)

| HTTP | `code` | When |
|---|---|---|
| 400 | `BAD_REQUEST` | Malformed JSON, unsupported content type |
| 401 | `UNAUTHENTICATED` | No session or token, expired or revoked token |
| 401 | `INVALID_CREDENTIALS` | Login failure (uniform, [ADR-0003](0003-authentication-with-sanctum.md)) |
| 403 | `FORBIDDEN` | Missing permission |
| 403 | `ADMIN_ACCESS_REQUIRED` | Non-admin account or non-admin client on `/admin` |
| 403 | `WORKSPACE_ACCESS_DENIED` | Admin lacks `workspaces.<key>.access`, or the Workspace is disabled ([ADR-0012](0012-administrative-workspaces.md)) |
| 403 | `ACCOUNT_INACTIVE` / `ACCOUNT_BANNED` | Status blocks the action ([ADR-0002](0002-account-classification-and-admin-access.md)) |
| 403 | `EMAIL_NOT_VERIFIED` | Action requires a verified email |
| 403 | `PASSWORD_CONFIRMATION_REQUIRED` | Sensitive action ([ADR-0004](0004-authorization-with-spatie-permission.md)) |
| 404 | `RESOURCE_NOT_FOUND` | Missing **or out of scope** ([ADR-0005](0005-category-level-authorization.md)) |
| 409 | `CONFLICT` / `ACCOUNT_LINK_REQUIRED` | State conflict, social link needed |
| 409 | `LAST_ADMIN_PROTECTED` | The action would remove the last owner |
| 422 | `VALIDATION_ERROR` | Form Request failure |
| 422 | `CATEGORY_NOT_ALLOWED`, `OTP_INVALID`, `OTP_EXPIRED`, `CAPTCHA_FAILED`, … | Business-rule violations |
| 426 | `CLIENT_UPGRADE_REQUIRED` | Client below the minimum supported version (§5) |
| 429 | `RATE_LIMIT_EXCEEDED` | Throttled; includes the `Retry-After` header |
| 500 | `INTERNAL_SERVER_ERROR` | Unexpected |
| 503 | `SERVICE_UNAVAILABLE` | Maintenance or provider outage |

- Codes live in a Core `ErrorCode` enum. Each module adds its codes in its own enum, prefixed when they could collide.
- **Adding** codes is non-breaking. **Renaming or removing** a code is a breaking change.

### 5. Versioning and multi-client compatibility

- **URI major versioning** (`/api/v1`). Within v1, changes are **additive only**: new endpoints, new optional fields, new error codes, new enum values announced in advance.
- **Breaking changes** create `/api/v2` for the affected endpoints only. v1 keeps working during a deprecation window of at least **6 months** after a mobile release that supports v2 (Q2). Deprecated endpoints send the `Deprecation` and `Sunset` headers (RFC 9745 / RFC 8594) and a `Link` header to the docs.
- **Clients send** `X-Client-Platform: admin-web|admin-ios|admin-android|public-ios|public-android|website` and `X-Client-Version: <semver>`.
  - The backend uses them for metrics and for the **minimum-version gate**: below the configured minimum, it returns 426 `CLIENT_UPGRADE_REQUIRED`.
  - It never uses them for authorization ([ADR-0003](0003-authentication-with-sanctum.md) handles client binding through the session or token).
- Clients must **ignore unknown fields** and handle unknown enum values gracefully. This is part of the client contract, and Zod schemas use `.passthrough()` or catch-alls ([ADR-0010](0010-frontend-contract-openapi-and-zod.md)).

### 6. Cross-cutting API rules

- **Content:** JSON only. `Accept: application/json` is required (otherwise 406). Request and response field names use `snake_case`.
- **Dates:** ISO 8601 UTC with offset (`2026-10-29T15:00:00Z`).
- **IDs:** integer IDs are exposed for internal entities (Q3 considers ULIDs for public-facing resources).
- **Filtering and sorting:** an explicit allow-list per endpoint (`?filter[status]=published&sort=-published_at`), validated by the Form Request. The default page size is 25, with a maximum of 100.
- **Rate limiting:** named Laravel limiters per route group ([ADR-0009](0009-audit-logging-and-security-controls.md)). The `X-RateLimit-*` headers are kept.
- **Idempotency:** `Idempotency-Key` support is **deferred** until payment-like operations exist.
- **Documentation:** OpenAPI 3.1, generated from code ([ADR-0010](0010-frontend-contract-openapi-and-zod.md)).

## Alternatives considered

- **Custom envelope** (the brief's illustration): acceptable; see §3 and Q1.
- **JSON:API** (`application/vnd.api+json`): heavy relationship and include semantics and verbose documents. Not needed. Rejected.
- **Header or media-type versioning:** harder to test, cache, and debug from mobile logs. Rejected in favor of URI versions.
- **GraphQL:** one more paradigm with no stated need. Rejected.

## Reasons and trade-offs

Standards (RFC 9457, URI versions, cursor pagination) reduce custom documentation. The main risk is client familiarity with Problem Details, which shared client error helpers mitigate.

## Database implications

None directly. Cursor pagination needs indexed, deterministic sort keys (for example `(published_at, id)`).

## Security implications

- No internal details in production errors.
- 404-for-out-of-scope responses.
- Uniform authentication errors.
- `trace_id` instead of exposing internals.
- Client headers never trusted for authorization.

## Performance implications

- Cursor pagination avoids deep `OFFSET` scans.
- Resources must eager-load to avoid N+1 queries. Feature tests assert the query count on list endpoints.

## Migration implications

None.

## Testing requirements

- Contract tests per endpoint: the success shape, and the problem shape for 401, 403, 404, 422, and 429, all matching the OpenAPI schema ([ADR-0010](0010-frontend-contract-openapi-and-zod.md)).
- The same request in `ar` and `en` returns the same `code` and status, with different `title`.
- With `APP_DEBUG=false`, a forced exception returns no stack trace.
- The minimum-version gate returns 426.

## Consequences

The Core exception renderer and the error-code enum must exist before the first module endpoint.

## Open questions

1. **Q1.** Problem Details (recommended) or the custom envelope? This decision is needed before phase 1.
2. **Q2.** What deprecation window can mobile release cycles support? *Recommended default: 6 months.*
3. **Q3.** Should public resources (articles, user profiles) use ULIDs or slugs instead of sequential IDs, to avoid enumeration? *Recommended default: slugs for articles and usernames for profiles in public URLs; integer IDs in the admin API.*

## Related ADRs and references

- [ADR-0001](0001-modular-monolith.md), [ADR-0007](0007-arabic-english-localization.md), [ADR-0009](0009-audit-logging-and-security-controls.md), [ADR-0010](0010-frontend-contract-openapi-and-zod.md)
- RFC 9457 (Problem Details), RFC 8594 (Sunset), RFC 9745 (Deprecation header)
