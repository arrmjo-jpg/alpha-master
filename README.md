# alpha-master

AlphaMaster: a Laravel 13 API on PHP 8.5 (`api/`) and its React web admin (`admin/`), run with Docker Compose.

| Part | Container | Local URL | Docs |
|---|---|---|---|
| API | `AlphaMaster-API` | http://localhost:8080 | `docs/` (ADRs, architecture) |
| Admin web | `AlphaMaster-Admin` | http://localhost:3000 | [`admin/README.md`](admin/README.md) |
| Database | `AlphaMaster-DB` | not published | |

```bash
cp .env.example .env   # then set APP_KEY and the passwords
docker compose up -d --build --wait
docker exec -it AlphaMaster-API php artisan users:create-owner
```

Or without prompts, with `SystemOwnerSeeder` (see the end of `.env.example`). It uses the same rules, never resets an existing account, and is not part of `db:seed`.

Tests run in their own containers (`AlphaMaster-TestDB`, `AlphaMaster-API-Test`):

```bash
docker compose -f docker-compose.test.yml run --rm --build --name AlphaMaster-API-Test api-test
docker compose -f docker-compose.test.yml down
```
