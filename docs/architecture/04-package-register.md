# E. Package evaluation register

- Versions were checked on Packagist on 2026-10-29 against the locked **Laravel 13.39.0** and the runtime **PHP 8.5.12**.
- The declared `composer.json` floor is `php ^8.3`. Packages needing PHP ^8.4 require raising it (ADR-0011 Q1).
- **No package was installed.**

| Package / capability | Purpose | Status | Latest stable (date) | Compatibility (verified) | Classification | Alternatives | Recommendation |
|---|---|---|---|---|---|---|---|
| `laravel/framework` | Framework | **Installed** 13.39.0 | — | — | Required | — | Keep |
| `laravel/tinker`, `pint`, `pail`, `pao`, `phpunit`, `mockery`, `collision`, `faker` | Dev tooling | **Installed** | — | — | Existing | — | Keep |
| `laravel/sanctum` | SPA session + API tokens | Proposed | v4.3.3 (2026-06-23) | `illuminate ^11–^13`, php ^8.2 ✅ | **Required, phase 1** | Passport, JWT | Adopt (ADR-0003) |
| `spatie/laravel-permission` | Roles and permissions | Proposed | 8.3.2 (2026-10-19) | `illuminate ^12–^13`, php ^8.3 ✅ (v8 is a major version: follow the v8 docs) | **Required, phase 1** | Custom Gates | Adopt (ADR-0004) |
| `dedoc/scramble` | OpenAPI 3.1 from code | Proposed (dev) | v0.13.52 (2026-10-28) | `illuminate ^10–^13` ✅ (pre-1.0: pin the minor version) | **Recommended, phase 1** | Scribe, hand-written YAML | Adopt (ADR-0010) |
| OpenAPI response validator (PHP) | Contract tests | Proposed (dev) | *not checked* | Verify in phase 1 | Recommended | Custom JSON-schema checks | Choose in phase 1 |
| `laravel/socialite` | Social login | Proposed | v5.31.0 (2026-08-31) | `illuminate ^6–^13` ✅ | Required **in the social phase** | Custom OAuth | Adopt when the phase starts |
| `socialiteproviders/apple` | Apple Sign-In | Candidate | *not checked* | Verify | Optional (if Apple is required) | Custom JWT verification | Evaluate in the social phase |
| `propaganistas/laravel-phone` | E.164 validation | Proposed | 6.1.0 (2026-10-02) | `illuminate ^11–^13` ✅ | Required **in the phone phase** | `giggsey/libphonenumber` directly | Adopt when the phase starts |
| SMS/OTP provider SDK | OTP delivery | Undecided | — | Must use HTTP/cURL (`proc_open` is disabled) | Required in the phone phase | Twilio Verify, Vonage, Unifonic, Taqnyat | **Owner decision** (cost and countries) |
| `intervention/image` | Image processing | Proposed | 4.4.0 (2026-10-17) | php ^8.3 ✅; needs the `gd`/`imagick` extension (**absent** today) | Required **in the media phase** | `spatie/image` | Adopt with the media phase |
| `spatie/laravel-medialibrary` | Per-model media | Evaluated | 11.23.11 (2026-10-26) | `illuminate ^12–^13` ✅; optimizers need `proc_open` (**disabled**) | **Not recommended** for a central reusable library | Custom `media` table | Alternative only (ADR-0008 Q2) |
| `spatie/laravel-translatable` | JSON translations | Evaluated | 6.15.0 (2026-10-19) | `illuminate ^12–^13`, **php ^8.4** (needs the floor raised) | Optional | Core `TranslatableJson` cast | Not now; adopt if translated content arrives |
| `spatie/laravel-activitylog` | Activity log | Evaluated | 5.1.3 (2026-10-19) | `illuminate ^12–^13`, **php ^8.4** | Optional | Custom `audit_logs`; owen-it | Second choice (ADR-0009) |
| `owen-it/laravel-auditing` | Model-diff audit | Evaluated | v14.0.6 (2026-06-20) | `illuminate ^11–^13` ✅ | **Unnecessary** | — | Rejected (misses non-model events) |
| `nwidart/laravel-modules` | Module framework | Evaluated | v13.3.0 (2026-10-28) | php ^8.3 ✅; adds `wikimedia/composer-merge-plugin` | **Unnecessary** now | internachi/modular, plain namespaces | Rejected (ADR-0001) |
| `internachi/modular` | Lightweight modules | Evaluated | 3.0.2 (2026-03-23) | `illuminate ^11–^13` ✅ | Optional | Plain namespaces | Fallback option |
| Laravel Fortify | Headless auth scaffolding / 2FA | Evaluated | *not checked* | Verify when 2FA is scheduled | Optional future | Custom TOTP | Re-evaluate with admin 2FA |
| CAPTCHA provider | Bot protection | Undecided | — | HTTP verification only | Required in a later phase | Turnstile, reCAPTCHA, hCaptcha | **Owner decision** (ADR-0009 Q4) |
| Larastan | Static analysis | Candidate (dev) | *not checked* | Verify Laravel 13 support | Optional | PHPStan alone | Evaluate in phase 1 |
| Pest | Test runner | Evaluated | *not checked* | — | Optional | PHPUnit 12 (installed) | Team choice (ADR-0011 Q2) |
| Redis (`phpredis` extension + service) | Cache, sessions, limiter, queue | Proposed (infrastructure) | — | Extension **absent** today | Recommended before production | Database drivers (current) | Add before production |
| Zod + OpenAPI-to-Zod generator (orval / openapi-zod-client / hey-api) | Frontend validation + types | Proposed (frontend) | *not checked* | The React project is not in the repo | Recommended when React joins | Hand-written types | Adopt with generation (ADR-0010) |

## Summary by category

1. **Already installed:** Laravel 13.39.0, Tinker, Pint, Pail, Pao, PHPUnit 12, Mockery, Collision, Faker.
2. **Recommended for initial implementation (phase 1):** Sanctum, Spatie Permission, Scramble (dev), an OpenAPI response validator (dev).
3. **Optional or future:**
   - Socialite, `socialiteproviders/apple`, `propaganistas/laravel-phone`, `intervention/image` (each with its phase)
   - `spatie/laravel-translatable`, Fortify, Larastan, Pest, Redis
4. **Unnecessary:** `nwidart/laravel-modules`, `owen-it/laravel-auditing`, and `spatie/laravel-medialibrary` (for the central-library requirement).
5. **Needs owner approval:**
   - SMS/OTP provider
   - CAPTCHA provider
   - object storage / CDN
   - raising the PHP floor to `^8.5`
   - Problem Details versus the custom envelope
   - Redis in production
