# Loomora: Progress Log

Claude Code appends one entry at the end of every session or task. Newest entries go at the bottom.

## Entry template

```md
### YYYY-MM-DD: T-000 <title>
- **Branch / PR:** ...
- **What changed:** ...
- **How it was verified:** (tests run, commands, output)
- **Problems:** ...
- **Questions:** ...
```

## Open questions

_Questions that need the planner or Danish. Includes any place where code and docs disagree._

- ~~**Q1**~~ Resolved 2026-10-24: B-002 (PR #2) merged, so B-003 onward branch from `v2-rebuild` as normal. Original question: These tasks need the Laravel app from B-002, which is still in review (PR #2). Branching them from `v2-rebuild` now gives no app to build or test. Should I wait for B-002 to merge (current choice), or stack the next branches on `feat/B-002-scaffold-laravel`?

- **Q3 (2026-10-24, B-005):** docs/09 says register "sends a verification email", but the verify/resend endpoints and Brevo (needs `BREVO_API_KEY`) are not in B-005. Register does not send an email yet. Suggest a separate task for email verification + Brevo once the key exists.
- **Q4 (2026-10-24, B-005):** docs/09 says `docs/openapi.yaml` is "kept in sync", but no task creates it yet. Add a task for it (and should every API task update it)?
- **Q5 (2026-10-24, B-005):** Choices to confirm: wrong password returns **422** with `errors.email` (Laravel convention, matches the error shape) rather than 401; minimum password length **8**; `/me` wraps the user in `data` (Laravel API resource default) while register/login return `{token, expires_at, user}`.

## Log

### 2026-10-24: B-001 Tidy repo for the rebuild
- **Branch / PR:** `feat/B-001-tidy-repo` (PR into `v2-rebuild`)
- **What changed:** `.gitignore` now ignores `legacy/vendor`, `legacy/node_modules`, `legacy/.env` (and `.env.*` except the two example files), `legacy/storage/*.key`. Added the "reference only" notice at the top of `legacy/README.md`. No mail-diagnostic code copied anywhere.
- **How it was verified:** created dummy `legacy/vendor/x`, `legacy/.env`, `legacy/storage/oauth.key`; `git check-ignore -v` matched all three to the new rules and `git status` showed only the intended edits (dummies removed after). `gitleaks dir .` -> no leaks found. `git ls-files` lists no `.env` or `.key` files.
- **Problems:** `legacy/README.md` already existed (the old Daycraft README). I put the notice on top and kept the old text below instead of replacing it, so nothing is deleted.
- **Questions:** none.

### 2026-10-24: B-002 Scaffold Laravel 12 app
- **Branch / PR:** `feat/B-002-scaffold-laravel` (PR into `v2-rebuild`)
- **What changed:** Fresh Laravel 12 skeleton in the repo root (`legacy/`, `docs/`, `README.md`, `CLAUDE.md`, `.gitignore` untouched). Example tests replaced by Pest 4 (`tests/Pest.php`). Pint included. Sanctum installed via `php artisan install:api`, `HasApiTokens` on `User`. `GET /api/v1/health` -> `{"status":"ok"}` with `tests/Feature/HealthTest.php`. `.env.example` lists the docs/10 backend variables with empty secret values.
- **How it was verified:** `php artisan test` -> 1 passed (2 assertions). `./vendor/bin/pint --test` -> passed. `php artisan serve --port=8123` + `curl localhost:8123/api/v1/health` -> `{"status":"ok"}`. Temporary `.env` deleted afterwards; nothing secret committed.
- **Problems:** none. The default `users` migration (integer id) is still the skeleton one; B-005 replaces it with the UUID version from docs/08.
- **Questions:** The skeleton keeps `package.json`, `vite.config.js` and `resources/` (Laravel defaults). The API only needs mail views; OK to remove the rest in a later task?

## Planner answers (2026-10-25)
- **Q1:** B-002 (PR #2) is merged into `v2-rebuild`. Branch B-003 to B-006 from `v2-rebuild` now. From now on, if a task needs an unmerged task, stack on its branch and say so in the PR instead of stopping.
- **B-002 question:** Yes. Add task B-007 to remove the unused Laravel frontend defaults (`package.json`, `vite.config.js`, `resources/js`, `resources/css`, welcome view). Keep mail views.
- **B-001 question:** Keeping the old README text under the notice is fine.

### 2026-10-24: B-005 Users table and email/password auth
- **Branch / PR:** `feat/B-005-auth` (PR into `v2-rebuild`)
- **What changed:** `users` migration rewritten to docs/08 (UUID id, nullable password, google_id, timezone, goal enum, heard_from, survey_done, streak fields; no remember_token). Sanctum `personal_access_tokens` and `sessions` now use UUID user ids. `User` uses `HasUuids`; only name/email/password/timezone are fillable; password and google_id hidden. `RegisterRequest`, `LoginRequest` (emails lower-cased), `UserResource`, `Api/V1/AuthController`. Routes: `POST /api/v1/auth/register`, `/auth/login` (throttle `auth`: 10/min per IP), `POST /auth/logout`, `GET /me` (Sanctum + throttle `api`: 120/min per user). Token expiry 30 days (`config/sanctum.php`). `/api/*` errors always render as JSON (401 instead of a redirect).
- **How it was verified:** `php artisan test` -> 15 passed (74 assertions) on sqlite, and the same 15 passed against Postgres 16 (Docker). `./vendor/bin/pint --test` -> passed. `migrate:fresh` on Postgres -> 6 migrations DONE; `users.id`, `personal_access_tokens.tokenable_id`, `sessions.user_id` are `uuid`. Tests cover register, duplicate email, validation, server-owned fields ignored (`id`, `user_id`, `current_streak`, `email_verified_at`), login, wrong password, unknown email, Google-only account, 30-day expiry, rate limit, me, 401 JSON, logout revokes only the current token, expired token rejected.
- **Problems:** a new user first serialised `current_streak: null` (DB defaults aren't on the in-memory model). Fixed with model `$attributes` defaults; covered by a test.
- **Questions:** Q3, Q4, Q5.

### 2026-10-24: B-003 GitHub Actions CI
- **Branch / PR:** `feat/B-003-ci` (PR into `v2-rebuild`)
- **What changed:** `.github/workflows/ci.yml`. Runs on every pull request and on pushes to `main` and `v2-rebuild`. PHP 8.3 (`shivammathur/setup-php`, `pdo_pgsql`), Composer cache keyed on `composer.lock`, Postgres 16 service, `.env` copied from `.env.example` + `key:generate`, then `pint --test` and `php artisan test`. `DB_CONNECTION=pgsql` and `DB_URL` are set as job env, which overrides the sqlite defaults in `phpunit.xml` (those `<env>` entries are not forced), so CI tests run against Postgres while local runs stay on in-memory sqlite.
- **How it was verified:** YAML parses. Locally ran the same steps: `composer install`, `cp .env.example .env`, `key:generate`, `pint --test` -> passed, `php artisan test` -> 1 passed. The Postgres part could not run locally (no Docker daemon in this environment); the PR's own CI run is the check for that ("Done when").
- **Problems:** none.
- **Questions:** none.
