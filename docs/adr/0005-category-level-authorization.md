# ADR-0005: Category-level content authorization

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** The principles are foundational; the schema and policy are deferrable until the Content module is built

## Context

- **[Fact]** No `categories` or `articles` tables, models, or routes exist. The category/content schema is **unknown and not yet designed**, so everything below is a proposal to be confirmed when the Content module is designed.
- Requirement: a global permission (for example `articles.update`) is necessary but not sufficient. The user must also be allowed to access the record's category. Admin status must not bypass this.

## Problem statement

How do we limit users to assigned categories consistently, across single-record operations, lists, search, exports, and bulk actions, without direct-object-reference or escalation gaps?

## Decision

### 1. Assignment model

- **Pivot table `category_user`:**
  - `category_id` (FK, cascade on category delete)
  - `user_id` (FK, cascade on user delete)
  - `granted_by` (FK users, nullable, set null)
  - `created_at`
  - `PRIMARY KEY (category_id, user_id)` and `INDEX (user_id)`
- Assignment and removal go through the Content actions `AssignCategories` and `RevokeCategories`. They require `categories.assign-users`, are audited, and apply the **grant ceiling**: an actor can only assign categories they can access themselves, unless they hold `categories.access-all`.

### 2. Access rules

| Situation | Rule |
|---|---|
| **No categories assigned** | **No access** to any category-scoped record. Empty never means "all". |
| **Unrestricted access** | Only through the explicit, audited permission **`categories.access-all`**. It is not implied by Admin and not granted by default. `system-owner` holds it because it holds all permissions. |
| **Hierarchy** ([Assumption] categories may be nested) | Assigning a category **includes its descendants**, including descendants created later. Access to a child does **not** give access to the parent. |
| **New top-level category** | Only users with `categories.access-all` see it until someone assigns it. |
| **Category moved under a new parent** | Effective access is recomputed. This is audited because it can widen access. |

A single policy service, `Content\Contracts\CategoryScope`, answers two questions:

- `allows(User, Category|int): bool` for one record
- `restrict(Builder, User): Builder` for queries

It loads the user's effective category IDs once per request. With nesting, it uses a closure table or a materialized path (Q1).

### 3. Enforcement on every path

- **Single-record operations** (`view`, `update`, `delete`, `restore`, `forceDelete`, `publish`): `ArticlePolicy::<ability>` = Spatie permission **AND** `CategoryScope::allows(article.category)`. Resolve route-model binding through a **scoped query** (`Article::visibleTo($user)`), so out-of-scope IDs return **404**, not 403. This avoids confirming that the record exists.
- **Create:** the request's `category_id` is validated with a rule that calls `CategoryScope::allows`. An out-of-scope category gets a 422 `CATEGORY_NOT_ALLOWED`.
- **Move between categories:** requires access to **both** the source and the target category.
- **Lists, search, filters, exports, counts, aggregates:** must start from `Article::visibleTo($user)`. Exports and reports run as queued jobs carrying the requester's ID and re-apply the scope inside the job.
- **Bulk operations:** each ID is authorized individually. The response lists the processed and the denied IDs. Nothing is processed silently or partially without telling the caller.
- **Soft delete, restore, force delete:** restore and force delete use `withTrashed()->visibleTo($user)`, so trashed records stay scoped.
- **Public/published content:** the public API serves only **published** articles. Category scope applies to authoring and administration, not to public reading (Q3).
- **Writers:** a writer edits only their **own** articles (`author_id = user`) in categories they can access. Ownership and scope are both checked.

### 4. Defense in depth

- A global Eloquent scope is **not** used for security. Global scopes are easy to bypass (`withoutGlobalScopes`) and leak into CLI and jobs. Instead, scope is applied explicitly through `visibleTo()`.
- An architecture test ensures that every admin Content controller's queries use `visibleTo` or a policy.
- Feature tests cover the out-of-scope ID cases for every endpoint (§ Testing).

## Alternatives considered

| Option | Verdict |
|---|---|
| Spatie *teams* keyed by category | Teams scope the **roles** of a user per team, which is a different concept. They would also force every permission check to carry a team context and would complicate multi-category users. Rejected. |
| Per-category permissions (`articles.update.category-5`) | The number of permissions explodes, they change with category CRUD, and they can't express inheritance. Rejected. |
| Row-level security in the database | MySQL has no native RLS. Rejected. |
| Empty assignment means all categories | Unsafe by default. Rejected. The brief requires otherwise unless the product owner approves it explicitly. |

## Reasons and trade-offs

One scope service with explicit query helpers is simple to test and audit. The cost is discipline: every query must use `visibleTo`. That discipline is enforced by tests rather than by implicit global scopes.

## Database implications

- `category_user` pivot.
- If categories are nested: `categories.parent_id` plus either a `category_closure` table (`ancestor_id`, `descendant_id`, `depth`) or `path` (materialized path). The choice is Q1.
- Indexes on `articles(category_id)` and `category_user(user_id)`.

## Security implications

Prevents insecure direct object references through 404-on-scope and per-ID bulk checks. Prevents escalation through the assignment grant ceiling and the auditing of moves.

## Performance implications

- One query per request for the user's effective category IDs; it can be cached per user with tag invalidation on assignment change.
- Lists use `WHERE category_id IN (...)`. A user with thousands of categories should use a join on the closure table instead of an `IN` list.

## Migration implications

Created with the Content module. The pivot table needs a later timestamp than `categories` and `users`.

## Testing requirements

For each Content endpoint, test:

- in scope: success
- out of scope: 404 (single record) or 422 (create/move)
- no assignments: an empty list
- `access-all`: everything visible
- an admin without `access-all`: still restricted
- inheritance to a child category, and to a child created after the assignment
- moves
- bulk with mixed IDs
- exports that respect scope
- trashed records still scoped

## Consequences

The category structure (flat or nested, single or multiple categories per article) must be decided before the Content module is designed.

## Open questions

1. **Q1.** Are categories nested? How deep? *Recommended default: nested, using a closure table.*
2. **Q2.** Can an article belong to multiple categories? If yes, does access require **any** of its categories or **all** of them? *Recommended default: one primary category; access is decided by the primary.*
3. **Q3.** Does category scope also restrict **reading** in the admin area, or only writing? *Recommended default: both, in the admin area.*
4. **Q4.** Should writers be scoped to categories at all, or may they write in any category? *Recommended default: scoped.*

## Related ADRs and references

[ADR-0002](0002-account-classification-and-admin-access.md), [ADR-0004](0004-authorization-with-spatie-permission.md), [ADR-0009](0009-audit-logging-and-security-controls.md)
