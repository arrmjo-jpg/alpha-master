# Architecture Decision Records — AlphaMaster

This folder holds the Architecture Decision Records (ADRs) for the AlphaMaster backend (`api/`) and the API contract it offers to the React admin app, the admin mobile app, the public mobile app, and the public website.

Supporting material (repository findings, module map, data model, package register, API matrix, security review, roadmap, and open questions) lives in [`../architecture/`](../architecture/README.md).

## Conventions

No ADRs existed before 2026-10-29, so this set establishes the convention:

- **File name:** `NNNN-kebab-case-title.md`, numbered sequentially and never reused.
- **Status values:** `Proposed` → `Accepted` | `Rejected`; later `Deprecated` or `Superseded by ADR-NNNN`.
- **Accepted ADRs are never edited in substance.** A changed decision gets a new ADR that supersedes the old one, and both files link to each other.
- **Required sections:** Title, Status, Context, Problem statement, Decision, Alternatives considered, Reasons and trade-offs, Database implications, Security implications, Performance implications, Migration implications, Testing requirements, Consequences, Open questions, Related ADRs and references.
- **Labels:** text marks each claim as **[Fact]** (verified in the repository on 2026-10-29), **[Assumption]**, or **[Recommendation]** where the difference matters.

## Index

All ADRs below are **Proposed**. Nothing has been implemented, and none of them is accepted until the product owner approves it.

| ADR | Title | Status | Priority |
|---|---|---|---|
| [0001](0001-modular-monolith.md) | Modular monolith architecture | Proposed | Foundational |
| [0002](0002-account-classification-and-admin-access.md) | Account classification, account status, and administrative access | Proposed | Foundational |
| [0003](0003-authentication-with-sanctum.md) | Authentication with Laravel Sanctum and multi-method login | Proposed | Foundational (email/username); deferrable (phone, social) |
| [0004](0004-authorization-with-spatie-permission.md) | Authorization with Spatie Permission, permission groups, and multilingual metadata | Proposed | Foundational |
| [0005](0005-category-level-authorization.md) | Category-level content authorization | Proposed | Deferrable to the Content module (principles foundational) |
| [0006](0006-api-contract-and-versioning.md) | API-first architecture, response/error contract, and versioning | Proposed | Foundational |
| [0007](0007-arabic-english-localization.md) | Arabic and English localization | Proposed | Foundational |
| [0008](0008-user-profile-and-media-library.md) | User profile data, central media library, and social links | Proposed | Profile fields foundational; media deferrable |
| [0009](0009-audit-logging-and-security-controls.md) | Audit logging, rate limiting, and CAPTCHA | Proposed | Audit and rate limits foundational; CAPTCHA deferrable |
| [0010](0010-frontend-contract-openapi-and-zod.md) | API contract tooling: OpenAPI, generated types, and Zod | Proposed | Foundational (OpenAPI); deferrable (Zod generation) |
| [0011](0011-dependencies-testing-and-ci.md) | Dependency strategy, testing, and continuous integration | Proposed | Foundational |
| [0012](0012-administrative-workspaces.md) | Administrative Workspaces | Proposed | Foundational |
| 0013 *(pending)* | Notification Center | Not yet written: the brief's Notification Center section was not received | Waiting on the full brief |

## Phase 1 approvals (2026-10-29)

The owner approved decisions **D1–D7** for Phase 1. They are listed in [08-phase-1-plan.md §5](../architecture/08-phase-1-plan.md) and implemented as described in [phase-1-users-access-control.md](../phase-1-users-access-control.md). They cover parts of ADR-0002, 0003, 0004, 0006, 0007 and 0012.

The ADR documents stay **Proposed** as a whole: only the listed decisions are approved, not every recommendation in those ADRs.

## Briefs these ADRs answer

1. The **Master Architecture Prompt**: modular Laravel app, auth, authorization, API, localization, user management (received 2026-10-29). Covered by ADRs 0001–0011.
2. The **ADR Update**: User Management, Authentication, Authorization, Modular Workspaces, and Notification Center (received 2026-10-29). **Only partly received:** the text stops in §6.2. The received parts are reflected in ADRs 0002, 0003, 0004, and 0012. The Workspace detail and Notification Center sections are still needed.
