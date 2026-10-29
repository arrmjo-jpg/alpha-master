# ADR-0008: User profile data, central media library, and social links

- **Status:** Proposed
- **Date:** 2026-10-29
- **Priority:** Profile fields are foundational; the Media Library is deferrable (only its integration point is decided now)

## Context

- **[Fact]** `users` has `name`, `email`, `email_verified_at`, `password`, `remember_token`, and timestamps. There is no username, phone, bio, avatar, social links, or status.
- **[Fact]** There is **no media infrastructure**: no media tables or packages; `FILESYSTEM_DISK=local`; no S3 or CDN config.
- **[Fact]** The API container has a **read-only root filesystem**. `storage/app` is a named Docker volume (`alphamaster-storage`), and only `/tmp` and some cache directories are writable.
- **[Fact]** The PHP image has `fileinfo`, but **no `gd`, `imagick`, or `exif`**, so no image processing is possible yet. `proc_open` and `exec` are **disabled** by `security.ini`, so image tools that shell out to binaries (`jpegoptim`, `ffmpeg`, the Spatie image optimizers) cannot run inside the PHP process.

## Problem statement

Which profile attributes belong on `users`, how are they validated and normalized, and how do the profile image and other modules use a central media library without storing URLs on business tables?

## Decision

### 1. Profile fields on `users` (no separate profile table)

All profile data has a 1:1 relationship with the user and is read together with it, so a separate `profiles` table would add a join without benefit.

| Field | Type / null | Uniqueness, index | Normalization and validation | Notes |
|---|---|---|---|---|
| `name` (existing) | VARCHAR(255) NOT NULL | — | Trim; 2–100 characters; Unicode letters, spaces, `-'.` | Kept as the full display name. No first/last split (it doesn't fit all Arabic naming conventions). |
| `username` | VARCHAR(30) NOT NULL | UNIQUE | Lowercase; regex in [ADR-0003](0003-authentication-with-sanctum.md); reserved list | Public handle, also a login identifier |
| `email` (existing) | VARCHAR(255) NOT NULL | UNIQUE | Trim + lowercase; RFC validation with DNS check optional | — |
| `email_verified_at` (existing) | TIMESTAMP NULL | — | — | — |
| `phone` | VARCHAR(16) NULL | UNIQUE | E.164; set only after OTP verification | [ADR-0003](0003-authentication-with-sanctum.md) |
| `phone_verified_at` | TIMESTAMP NULL | — | — | — |
| `bio` | TEXT NULL | — | Trim; ≤ 1000 characters; plain text only (no HTML; clients escape) | — |
| `avatar_media_id` | BIGINT UNSIGNED NULL | FK → `media.id`, `ON DELETE SET NULL` | Must reference an image owned by this user and marked `purpose=avatar` | Added in the **Media phase** |
| `social_links` | JSON NULL | — | §3 | — |
| `preferred_locale` | VARCHAR(8) NULL | — | In `supported_locales` | [ADR-0007](0007-arabic-english-localization.md) |
| `account_type` | VARCHAR(16) NOT NULL DEFAULT 'user' | INDEX | Enum | [ADR-0002](0002-account-classification-and-admin-access.md) |
| `deactivated_at` / `deactivated_by` | TIMESTAMP / FK NULL | — | — | [ADR-0002](0002-account-classification-and-admin-access.md) |
| `banned_at` / `banned_by` / `banned_until` / `ban_reason` | TIMESTAMP / FK / TIMESTAMP / TEXT NULL | — | — | `ban_reason` is admin-only and never in public resources |
| `deleted_at` | TIMESTAMP NULL | — | SoftDeletes | Supports `users.delete`/`restore`/`force-delete` |

- **Soft delete and uniqueness:** a soft-deleted user still holds their unique email and username, so they cannot be re-registered while restorable. **Force-delete** frees them. *Product question Q1: anonymize instead of hard delete?*
- **Mass assignment:** `$fillable` = `name`, `username`, `bio`, `social_links`, `preferred_locale`. Every other column changes only through a dedicated action.

### 2. Central Media Library (Media module, its own phase)

**[Recommendation]** A **custom, thin central asset table** plus explicit references, rather than `spatie/laravel-medialibrary`'s per-model polymorphic collections.

- **Why custom:**
  - The brief asks for a *central* library whose assets can be **reused** across modules and referenced by stable ID.
  - **[Fact]** `spatie/laravel-medialibrary` 11.23.11 is compatible with Laravel 13, but it attaches each media row to **one** owning model (`model_type`/`model_id`) and duplicates files for reuse.
  - Its optimizer and video features depend on external binaries started through `proc_open`, which this hardened runtime disables.
  - Its conversions still need `gd`/`imagick`.
  - It remains a valid choice if reuse is dropped from the requirements (Q2).
- **`media` table:**
  - `id`, `uuid` (public identifier), `disk`, `path`, `original_name`, `mime_type` (detected by `fileinfo`, not by the client)
  - `size_bytes`, `width`, `height`, `checksum_sha256`
  - `visibility` (`public|private`), `purpose` (`avatar|article|attachment|…`), `uploaded_by` (FK users, set null)
  - `alt_text` (JSON `{ar,en}`, nullable), timestamps, `deleted_at`
- **`media_conversions`** (optional, phase 2): `media_id`, `name` (`thumb`, `md`), `path`, `width`, `height`.
- **References:**
  - Business tables hold **FKs to `media.id`** (`users.avatar_media_id`, `articles.cover_media_id`), with `ON DELETE SET NULL` or `RESTRICT` chosen per use.
  - Many-to-many uses (article galleries) get their own pivot (`article_media`).
  - **No URLs are stored on business tables.** URLs are generated at serialization time by the Media module's `MediaUrlGenerator` contract (public CDN URL, or a short-lived signed URL for private assets).
- **Storage:** Laravel Filesystem disks only.
  - Locally, the `local`/`public` disks on the `alphamaster-storage` volume.
  - In production, an S3-compatible disk with a CDN in front for public assets (Q3).
  - Module code never calls the disk directly; it goes through the Media module contracts.
- **Uploads:**
  - `POST /api/v1/media` (multipart) requires `media.upload` for admin purposes. A self-service avatar upload goes through `POST /api/v1/me/avatar`, which needs no permission and only applies to the actor's own profile.
  - Validation: an allow-list of MIME types (`image/jpeg`, `image/png`, `image/webp`; no SVG unless sanitized), a maximum size (avatar 2 MB; the [Fact] PHP `upload_max_filesize=10M` caps everything), pixel dimension limits, and image decoding to reject polyglots.
  - Stored file names are server-generated (UUID), never the client's.
- **Image processing:** in queued jobs. Requires adding the `gd` (or `imagick`) extension to the Dockerfile in the Media phase, plus a pure-PHP library (`intervention/image` 4.4.0, [Fact] PHP ^8.3). Processing re-encodes images, which also **strips EXIF/GPS metadata**.
- **Replacing an avatar:** create the new media row, then swap the FK in a transaction, then soft-delete the old asset if nothing else references it.
- **Orphan cleanup:** a scheduled job finds media with no references and older than 24 h (uploads abandoned before attach), soft-deletes them, then purges the files after a grace period. Each referencing module exposes its reference columns to Media through a registry, so the job can tell whether an asset is used.
- **Access control:**
  - Public assets are served by the CDN or web server.
  - Private assets are only served through signed, expiring URLs issued after a policy check.
  - Media rows carry `uploaded_by`. Deleting someone else's asset requires `media.delete`.
- **Future video and files:** the same table, with different `purpose` and MIME allow-lists. Transcoding needs an external service or worker, because `proc_open` and `ffmpeg` are unavailable in the API runtime.

### 3. Social profile links

- `users.social_links` JSON: an **object keyed by platform**, e.g. `{"x": "https://x.com/sara", "linkedin": "https://www.linkedin.com/in/sara"}`.
- **Allowed keys** (config-driven list): `facebook`, `instagram`, `x`, `linkedin`, `youtube`, `tiktok`, `snapchat`, `github`, `website`. Unknown keys are rejected.
- **Validation:**
  - `https` only.
  - ≤ 2048 characters.
  - For known platforms, the host must match that platform's domains (for example `x.com`/`twitter.com`).
  - `website` allows any public host, but rejects IP literals, `localhost`, and private ranges, which prevents SSRF if previews are ever fetched.
  - No `javascript:`, `data:`, or userinfo in the URL.
  - Empty strings and `null` values are removed. At most 10 entries.
- **Serialization:** returned as-is. Clients render the links with `rel="noopener nofollow ugc"`.
- These are **public profile links**, kept separate from the OAuth `user_identities` ([ADR-0003](0003-authentication-with-sanctum.md)).

## Alternatives considered

| Option | Verdict |
|---|---|
| Separate `user_profiles` table | No 1:1 split benefit. Rejected. |
| Avatar URL string on `users` | Breaks storage/CDN changes and private assets, and leaves orphans. Rejected. |
| `spatie/laravel-medialibrary` | Strong package, but per-model ownership conflicts with central reuse, and its optimizers need `proc_open`. Alternative if reuse is dropped (Q2). |
| `social_links` as an array of `{platform, url}` objects | Allows duplicates and needs extra uniqueness checks. The keyed object is simpler. |
| `user_social_links` table | Only needed for querying users by platform, which isn't a requirement. Deferred. |

## Reasons and trade-offs

A custom media table costs some code (upload, validation, URL generation, cleanup), but matches the "central and reusable" requirement and the hardened runtime. Profile fields on `users` keep reads to one query.

## Database implications

- Additive `users` columns.
- `media` (+ optional `media_conversions`, `article_media`) in the Media phase.
- The FK from `users` to `media` is added in a Media-phase migration with a later timestamp, so Users does not depend on Media at the schema level until then.

## Security implications

- Server-side MIME detection.
- Re-encoding strips EXIF and blocks polyglots.
- No SVG unless sanitized.
- Generated file names.
- Private assets only through signed URLs.
- `ban_reason` and internal fields excluded from public Resources.
- Social link validation prevents `javascript:` URLs and SSRF.

## Performance implications

- Conversions run in the background (a queue worker container is required; **[Fact]** none exists today).
- A CDN serves public files.
- Avatar URLs are computed without extra queries when the `media` relation is eager-loaded.

## Migration implications

- Phase 1: additive `users` columns.
- Media phase: `media` tables, the `gd` extension in the Dockerfile, an S3 disk config, and a queue worker and scheduler in Compose.

## Testing requirements

- Profile validation for each field (Arabic names accepted; reserved usernames rejected).
- Mass-assignment protection.
- Social link validation (bad scheme, wrong host, private IP, unknown key).
- Media phase: MIME spoofing rejected, size limits, EXIF stripped, avatar ownership enforced, signed URL expiry, orphan cleanup.

## Consequences

The Media phase needs infrastructure changes (an image extension, object storage, workers), which are planned in the roadmap.

## Open questions

1. **Q1.** User deletion: soft-delete with restore, plus hard delete, or anonymization for privacy requests? *Recommended default: soft delete; force delete anonymizes personal data while keeping the authored-content relationships.*
2. **Q2.** Must media assets be reusable across records (true central library)? *Recommended default: yes, with the custom table.*
3. **Q3.** Which object storage and CDN (AWS S3, Cloudflare R2, DigitalOcean Spaces)?
4. **Q4.** Should the bio support limited formatting (links, line breaks)? *Recommended default: plain text with line breaks.*

## Related ADRs and references

[ADR-0002](0002-account-classification-and-admin-access.md), [ADR-0003](0003-authentication-with-sanctum.md), [ADR-0007](0007-arabic-english-localization.md), [ADR-0009](0009-audit-logging-and-security-controls.md); OWASP File Upload Cheat Sheet
