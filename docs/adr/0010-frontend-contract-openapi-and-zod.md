# ADR-0010: API contract tooling: OpenAPI, generated types, and Zod

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** OpenAPI generation is foundational; Zod generation can wait until the React app joins

## Context

- **[Fact]** There is **no React, TypeScript, or mobile code** in this repository. `api/src/package.json` only has the Laravel skeleton's Vite and Tailwind tooling, with no React or Zod. The frontend stack details (TypeScript version, router, data-fetching library) are therefore unknown.
- **[Fact]** `dedoc/scramble` v0.13.52 (2026-10-28) declares `illuminate/contracts ^10–^13`, so it is compatible. It is still **pre-1.0**, which means minor releases may break.
- Zod is a TypeScript runtime-validation library. It cannot validate requests on the server and doesn't replace Laravel validation.
- Four clients consume the API. Divergent hand-written types are the main risk.

## Problem statement

How do we keep one source of truth for the API contract, give the React app type-safe validated access, and catch contract drift automatically?

## Decision

### 1. The backend code is the source of truth, and OpenAPI is the published contract

- **[Recommendation]** Generate **OpenAPI 3.1** from the Laravel code with **Scramble**.
  - Scramble infers request schemas from **Form Requests** and response schemas from **API Resources**, so the documentation follows the same code that validates and serializes.
  - Annotations are added where inference falls short: the Problem Details error responses, cursor-pagination meta, and enum descriptions.
- The documentation UI (`/docs/api`) is available in non-production environments only, or behind admin authentication.
- **CI:**
  - `php artisan scramble:export` writes `api/openapi.json`, which is committed.
  - A CI step fails if the exported file differs from the committed one, so every contract change shows up in the pull request diff.
  - A breaking-change check (e.g. `oasdiff`) against `main` is optional, but recommended once the mobile apps ship.
- **Contract tests (backend):** feature tests validate actual responses against `openapi.json` (an OpenAPI response-validator library, chosen and checked for compatibility in phase 1). This catches cases where the documentation and the behavior diverge, including error responses.

### 2. React admin: generated types and generated Zod schemas, plus thin hand-written form schemas

- Generate **TypeScript types and Zod schemas from `openapi.json`** with an OpenAPI-to-Zod generator (candidates: `orval` with its Zod output, `openapi-zod-client`, `@hey-api/openapi-ts`). Choose and check versions when the React project is set up. *[Not verified: these JS packages were not version-checked in this ADR.]*
- Use the generated Zod schemas to **parse API responses** at the data-layer boundary. Schemas should be lenient for unknown keys (`.passthrough()` or default strip), so additive API changes don't break older builds ([ADR-0006](0006-api-contract-and-versioning.md)).
- **Form validation:** compose form schemas from the generated request schemas, adding UX-only refinements such as password-confirmation matching or localized client messages. Don't redefine field rules by hand.
- **Laravel validates everything again.** Frontend validation is a UX aid, never a security boundary. A 422 Problem Details response maps field `errors` back onto the form.
- **Limits of generation:** OpenAPI can't express every Laravel rule (`unique:users`, `exists`, custom closures, cross-field business rules). Those stay server-only and show up as 422 errors. Don't try to mirror them in Zod.

### 3. Mobile clients

The mobile apps generate their own models from the same `openapi.json` (e.g. `openapi-generator` for Kotlin or Swift, or `freezed` + `openapi` generators for Flutter). That decision belongs to the mobile teams; the contract is shared.

### 4. Repository layout for the contract

Commit `openapi.json` at `api/openapi.json`. If the frontends live in other repositories, publish it as a CI artifact or package on each `main` build, and the frontends pin a version.

## Alternatives considered

| Option | Verdict |
|---|---|
| Hand-written OpenAPI (YAML) | Drifts from the code. Rejected as the primary approach; allowed for supplements. |
| Generating Zod directly from Laravel rules (custom tooling) | Brittle and incomplete (database rules, closures). Rejected. |
| Hand-written Zod and TS types on the frontend | Duplicates the contract and drifts silently. Rejected. |
| `knuckleswtf/scribe` (documentation generator) | Strong for docs, but its OpenAPI is less type-precise for Resources. Scramble fits the type-generation goal better. Alternative. |
| tRPC / GraphQL codegen | Changes the API paradigm. Rejected. |

## Reasons and trade-offs

One generated contract feeds documentation, backend contract tests, TypeScript types, and Zod. The trade-off is Scramble's pre-1.0 status: pin the minor version and review updates. The contract test layer makes generator mistakes visible.

## Database implications

None.

## Security implications

- No security reliance on client validation.
- API docs are not public in production unless deliberately published.
- The exported schema must not contain secrets or internal-only endpoints. Admin endpoints are documented, which is acceptable, because their security is authorization, not obscurity. Q1 decides whether admin docs should be private.

## Performance implications

- Scramble runs at build or CI time (and on the docs route in development only). No runtime cost in production.
- Response parsing with Zod has a small client-side cost, which is acceptable.

## Migration implications

None (adds a dev dependency in phase 1).

## Testing requirements

- CI: `openapi.json` is up to date.
- Contract tests for representative success and error responses per module.
- Frontend: a generated-client build passes typecheck against the current spec.

## Consequences

Backend developers must keep Form Requests and Resources accurate, because they drive the client types. Thin controllers ([ADR-0006](0006-api-contract-and-versioning.md)) make this natural.

## Open questions

1. **Q1.** Do the React admin and mobile apps live in this repository (monorepo) or separately? This affects how `openapi.json` is shared.
2. **Q2.** React stack details (TypeScript strictness, data-fetching library), which affect the generator choice.
3. **Q3.** Should the admin API documentation be reachable in production behind auth, or only in non-production environments?

## Related ADRs and references

[ADR-0006](0006-api-contract-and-versioning.md), [ADR-0011](0011-dependencies-testing-and-ci.md); OpenAPI 3.1 specification; Scramble documentation
