# AlphaMaster Admin

The web admin for the AlphaMaster API: React 19, TypeScript, Vite 6, Tailwind 3, TanStack Query, react-i18next, Zod 4.
Arabic (RTL) is the default; English (LTR) is supported. Light and dark mode.

## Screens

| Route | What it shows | Needs |
|---|---|---|
| `/login` | Sign in with email or username | — |
| `/` | Overview: your roles, counts, recent audit events | an admin session |
| `/roles` | Every role with its key and number of accounts | `roles.view` |
| `/roles/:id` | One role and its permissions, grouped | `roles.view` (`permissions.view` for translated names) |
| `/permissions` | The permission catalog, searchable | `permissions.view` |
| `/audit` | The audit log, filterable by event, outcome and date, with cursor paging | `audit.view` |

This version is **read-only**: it does not create or change roles, permissions or users yet. The API already supports those operations.

## How it talks to the API

- **Sanctum cookie session** (ADR-0003). No token is ever stored in JavaScript or `localStorage`.
- Unsafe requests send `X-XSRF-TOKEN`. A stale token (HTTP 419) is refreshed once automatically.
- The SPA and the API share **one origin**:
  - in development, Vite proxies `/api` and `/sanctum` to `http://localhost:8080` (change it with `ALPHAMASTER_API_URL`);
  - in Docker, nginx proxies them to the `api` container.
- Errors are RFC 9457 Problem Details. The API translates `title` and `detail` through `Accept-Language`, so the UI shows them as they come. A 422 maps field errors back onto the form.
- **Admin only.** The API's admin routes refuse anything else; the UI adds a convenience on top:
  - a non-admin account that signs in is signed straight out again, with a clear message;
  - navigation hides links the account has no permission for.

  Neither is a security control: every request is authorized on the server.
- A 401 from any request (expired or revoked session) sends the user to `/login`, then back to the page they were on.

## Run it

**With Docker** (the whole stack). Add `docker-compose.admin.yml` to `COMPOSE_FILE` (as in `.env.example`), then:

```bash
docker compose up -d --build --wait
```

The admin is at <http://localhost:3000> (`ADMIN_PORT`). `localhost:3000` is already in the API's default `SANCTUM_STATEFUL_DOMAINS`.

**For development**, with the API running on port 8080:

```bash
npm --prefix admin install
npm --prefix admin run dev
```

The dev server runs at <http://localhost:5173>.

**First administrator:** there is no sign-up. Create the owner on the server:

```bash
docker exec -it AlphaMaster-API php artisan users:create-owner
```

## Checks

```bash
npm --prefix admin run typecheck
npm --prefix admin test
npm --prefix admin run build
```

CI runs these in the **Admin web** job. The Docker job also builds the image and checks the proxy, the CSP header and the non-root user.

## Container

- `nginxinc/nginx-unprivileged`, read-only root filesystem, all capabilities dropped, `no-new-privileges`.
- It serves only static files plus the proxy.
- Strict CSP (`default-src 'self'`, no inline scripts: the theme bootstrap is `public/boot.js`), `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`.
  - Sanctum identifies the SPA by its Referer, so keep the referrer policy at `same-origin`.
- Fonts are self-hosted (`@fontsource`), so no third-party requests.

## Design

The direction is the approved "panel docked on the brand color":

- **Sign-in:** a flat white sheet docked to the inline-start edge of a full-screen `#3B7597` field, carrying a large `AlphaMaster` wordmark.
- **Signed in:** the same blue becomes the navigation rail.

Rules:

- No rounded corners: Tailwind's `borderRadius` scale is all `0`.
- No illustrations.
- Fonts: Tajawal for Arabic, Inter for Latin. Stable identifiers (permission keys, event names, emails) always render LTR in Inter.
- Motion: one 220 ms entrance, plus `scale(0.97)` when a button is pressed. Both are switched off under `prefers-reduced-motion`.
- Tokens live in `src/styles.css`. The minimum measured text contrast is 5.0:1 in both themes.

## Known limits

- **Response schemas** (`src/lib/schemas.ts`) are written by hand from the API Resources. ADR-0010 plans to generate them from `openapi.json` once Scramble is set up.
- **Audit IP through the container:** behind the admin container, the API records nginx's IP, not the browser's. The API does not trust `X-Forwarded-For` yet; trusting the proxy is a backend decision (`trustProxies`).
- **No password reset:** the API has no endpoint for it, so the sign-in page tells administrators to ask the system owner.
