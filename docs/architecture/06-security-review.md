# G. Security review (of the proposed architecture)

| # | Risk | Where | Mitigation (ADR) | Residual risk |
|---|---|---|---|---|
| 1 | **Admin bypass through client manipulation** (hidden React buttons, a forged `account_type` in a request) | All admin APIs | Server-side gate chain (account type → admin client → Workspace → permission → scope); `account_type` never accepted from input; `$fillable` allow-list (0002, 0012) | Low |
| 2 | **Implicit full access for Admin** | Authorization | No `Gate::before`; `system-owner` is an explicit, protected role (0002, 0004) | Low |
| 3 | **Privilege escalation through role/permission assignment** | AccessControl | Grant ceiling; no self-assignment; protected roles; sensitive-permission re-confirmation; role `scope` must match the account type; audit (0004) | Low–medium (depends on role design discipline) |
| 4 | **Escalation through account-type change** | Users | Dedicated action + permission + password confirmation + audit; never self; last-admin invariant (0002) | Low |
| 5 | **Workspace leakage** (data from one admin area reachable from another) | Admin routes | `workspace:` middleware on every admin route, enforced by an architecture test; narrow lookup endpoints (0012) | Low |
| 6 | **Insecure direct object reference across categories** | Content | Scoped route binding (404); `visibleTo()` on every query; per-ID bulk authorization; exports re-scoped in jobs (0005) | Medium until the tests cover every endpoint |
| 7 | **Social login takeover through email matching** | Identity | No auto-linking; `ACCOUNT_LINK_REQUIRED`; server-side ID-token verification (0003) | Low |
| 8 | **Phone squatting / SIM-swap recovery** | Identity | `phone` set only after OTP; OTP hashed, short-lived, attempt-limited; admins should not rely on SMS-only recovery; admin 2FA (TOTP) recommended (0003 Q7) | Medium for SMS-based recovery (inherent) |
| 9 | **Token theft / long-lived tokens** | Mobile | Per-device tokens with expiry; device revocation; secure device storage guidance; revocation on password reset, ban, or deactivation (0003) | Medium: no refresh rotation (an accepted trade-off) |
| 10 | **Banned or inactive users keep access** | All | Status check at login **and** on every request; immediate token and session deletion (0002) | Low |
| 11 | **CSRF / CORS misconfiguration** for the SPA | React admin | Sanctum stateful domains, exact CORS origins with credentials, `SameSite=Lax` + `Secure` cookies, XSRF header (0003); depends on the domain topology (Q1) | Medium until the domains are fixed |
| 12 | **User enumeration** | Login, reset, OTP | Uniform responses and timing; rate limits (0003, 0009) | Low |
| 13 | **Brute force / credential stuffing** | Login | Per-identifier + IP limiters; progressive lockout; adaptive CAPTCHA; audit (0009) | Low–medium (a distributed attack needs CDN/WAF support) |
| 14 | **Sensitive data in responses or logs** | API, audit | Problem Details without internals when `APP_DEBUG=false`; allow-list audit context; no tokens, passwords, or OTP in logs; `ban_reason` admin-only (0006, 0009) | Low |
| 15 | **Malicious uploads** (polyglots, SVG XSS, EXIF GPS leaks) | Media | Server-side MIME detection, re-encode, no raw SVG, generated names, signed private URLs (0008) | Low once the media phase is implemented |
| 16 | **Stored XSS / unsafe links** in bio and social links | Users | Plain-text bio; https-only, host-checked, no private IPs; clients escape output (0008) | Low |
| 17 | **Locale affecting behavior** | All | Locale only selects text; tests assert identical status and codes across `ar`/`en` (0007) | Low |
| 18 | **Lost administrative access** | Users | Last-admin invariant with row locks; CLI-only recovery with audit (0002) | Low |
| 19 | **Supply chain** | Dependencies | Dependency policy, `composer audit` in CI (exists), version pinning of pre-1.0 packages (0011) | Medium (inherent) |
| 20 | **Test/production parity** (SQLite vs MySQL) hiding uniqueness and locking bugs | CI | MySQL service for the feature and security suites (0011) | Low after phase 1 |
| 21 | **Spatie guard mismatch** (permissions created under one guard, checked under another) | AccessControl | Single `web` guard for everything; explicit test with Sanctum tokens (0003 §5, 0004 §1) | Low |
| 22 | **Rate limiter or shared IP behind a proxy** | Infrastructure | Trusted-proxy configuration (0009 Q3) | Medium until configured |

## Compatibility issues found

1. **The `composer.json` PHP floor (`^8.3`) is lower than the runtime (8.5.12).** Packages requiring PHP ^8.4 cannot be installed without raising it. Recommendation: `^8.5`.
2. **Tests run on SQLite while production uses MySQL 8.4.** Collation, JSON, and locking differ.
3. **`proc_open`/`exec` are disabled** (intentional hardening). This rules out in-process binary optimizers and video tools, and any package that shells out. All provider SDKs must be HTTP-based.
4. **`mail()` is disabled.** Production mail needs SMTP or an API mailer.
5. **No `gd`/`imagick`/`exif`/`redis` extensions.** They must be added to the Dockerfile in the relevant phases.
6. **No queue worker or scheduler container.** These are needed for token pruning, OTP cleanup, media conversions, exports, and audit retention.
7. **Spatie Permission v8** is newer than most public guidance (v6). Implementation must follow the v8 documentation (`BackedEnum` support, the v7 renames).
