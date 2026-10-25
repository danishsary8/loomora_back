# Loomora: Task Queue

The queue Claude Code works from. Take the first task with status `todo`, top to bottom.
Status values: `todo` | `doing` | `review` | `done` | `blocked`

Rules:
- One task is about 1 to 2 hours of work and touches one concern.
- One task = one branch (`feat/<id>-short-name`) = one PR into `v2-rebuild`. Tests must pass before the PR.
- A `blocked` task must state a clear question. Then move on to the next task.
- "Done when" items must be checkable by running something.
- Read docs/08-data-model.md, 09-api-design.md and 10-tech-and-structure.md before any task that touches data, API or structure.

## Queue

### B-001: Tidy repo for the rebuild
- **Status:** done
- **Steps:**
  1. Fix `.gitignore` so `legacy/vendor`, `legacy/node_modules`, `legacy/.env`, `legacy/storage/*.key` are ignored.
  2. Add `legacy/README.md`: "Old Daycraft code. Reference only. Do not run or import. Deleted at the end of R1."
  3. Do not copy the legacy mail-diagnostic or mail-test endpoints anywhere.
- **Done when:**
  - [x] `git status` is clean after the change and no secrets are tracked
  - [x] `legacy/README.md` exists
- **Files:** `.gitignore`, `legacy/README.md`

### B-002: Scaffold Laravel 12 app
- **Status:** done
- **Steps:**
  1. Create a fresh Laravel 12 app in the repo root (PHP 8.3), without touching `legacy/` or `docs/`.
  2. Install Pest and Laravel Pint. Install Sanctum and set up API tokens.
  3. Add route `GET /api/v1/health` returning `{"status":"ok"}` and a Pest test for it.
  4. Add `.env.example` with the empty variables listed in docs/10-tech-and-structure.md.
- **Done when:**
  - [x] `php artisan test` passes
  - [x] `./vendor/bin/pint --test` passes
  - [x] `php artisan serve` then `curl /api/v1/health` returns ok
- **Files:** `composer.json`, `routes/api.php`, `tests/Feature/HealthTest.php`, `.env.example`

### B-003: GitHub Actions CI
- **Status:** done
- **Steps:**
  1. Add `.github/workflows/ci.yml`: PHP 8.3, composer install (cached), Postgres 16 service, run Pint check and `php artisan test`.
  2. Run on pull requests and pushes to `main` and `v2-rebuild`.
- **Done when:**
  - [ ] The workflow runs green on the PR
- **Files:** `.github/workflows/ci.yml`

### B-004: Dockerfile and Render blueprint
- **Status:** done
- **Steps:**
  1. Add a production `Dockerfile` (PHP 8.3 + Apache or php-fpm + nginx) that runs migrations on start with `php artisan migrate --force` and fails loudly if they fail.
  2. Add `render.yaml` for a service named `loomora-api` (free plan, Docker, health check `/api/v1/health`), listing env variable NAMES only.
  3. Do not deploy. Do not add secrets.
- **Done when:**
  - [ ] `docker build .` succeeds
  - [x] `render.yaml` lists all variables from docs/10 with no values
- **Files:** `Dockerfile`, `render.yaml`, `.dockerignore`

### B-005: Users table and email/password auth
- **Status:** done
- **Steps:**
  1. Migration and model for `users` exactly as in docs/08-data-model.md (UUID id).
  2. Endpoints: register, login, logout, me (docs/09). Sanctum bearer tokens, 30-day expiry.
  3. Form requests for validation, API resources for output, rate limits on auth routes.
  4. Pest tests: register, login, wrong password, me, logout.
- **Done when:**
  - [x] `php artisan test` passes with the new tests
  - [x] No endpoint accepts `user_id` from the client
- **Files:** `database/migrations/*users*`, `app/Models/User.php`, `app/Http/Controllers/Api/V1/AuthController.php`, tests

### B-006: To-dos API
- **Status:** done
- **Steps:**
  1. Migration, model, policy and controller for `todos` per docs/08 and docs/09.
  2. `GET /todos?date=` implements the Today list rules and the computed `overdue` flag.
  3. Done and undone endpoints.
  4. Pest tests for every rule: due today, undated, overdue, future hidden, other users' data not visible.
- **Done when:**
  - [x] All tests pass
  - [x] A user cannot read or change another user's to-do (test proves it)
- **Files:** migration, `app/Models/Todo.php`, `TodoController.php`, tests

### B-007: Remove unused Laravel frontend defaults
- **Status:** done
- **Steps:**
  1. Remove `package.json`, `vite.config.js`, `resources/js`, `resources/css` and the `welcome` view and web route. Keep mail views and API-only routing.
  2. Make sure `php artisan test` and Pint still pass.
- **Done when:**
  - [x] Tests and Pint pass; `GET /api/v1/health` still returns ok
- **Files:** `package.json`, `vite.config.js`, `resources/*`, `routes/web.php`

### B-008: Email verification and password reset (Brevo)
- **Status:** done
- **Steps:**
  1. Endpoints from docs/09: email/verify, email/resend, password/forgot, password/reset. Registration sends the verification email.
  2. Mail through the Laravel mailer with an env-driven transport: `log` driver in local/test, Brevo (API or SMTP) in production. Env names only, no values. Plain, branded HTML mail templates (English, text via lang files).
  3. Pest tests with `Mail::fake()`: verify, resend (throttled), forgot, reset, expired/invalid token.
- **Done when:**
  - [x] Tests and Pint pass; no secret in the repo
  - [x] If a Brevo key is needed to test for real, mark that part `blocked` with the exact question
- **Files:** controllers, mail classes/templates, tests

### B-009: OpenAPI contract file
- **Status:** done
- **Steps:**
  1. Create `docs/openapi.yaml` for every endpoint built so far (health, auth, me, todos) with request/response examples and the error shape from docs/09.
  2. Add a short "keep in sync" note to CLAUDE.md. Document the current response shapes: login and register return `{token, expires_at, user}`; `/me` returns `{data: user}`.
- **Done when:**
  - [x] The file validates with an OpenAPI linter (e.g. `npx @redocly/cli lint docs/openapi.yaml`)
- **Files:** `docs/openapi.yaml`, `CLAUDE.md`

### B-010: Schedule items API
- **Status:** done
- **Steps:**
  1. Migrations, models, policies, controllers for `schedule_items` per docs/08 and docs/09 (create, list, edit all repeats, delete all repeats). Validate start < end, days non-empty, limit 100 per user.
  2. Pest tests including cross-user access denied.
- **Done when:**
  - [x] Tests and Pint pass
- **Files:** migrations, `ScheduleItem` model, controller, requests, resources, tests

### B-011: Schedule occurrences, exceptions, check-ins, now/next
- **Status:** done
- **Steps:**
  1. Build `ScheduleService` that returns occurrences for a date or a week (docs/08 rule 4), applying skip and override exceptions.
  2. Endpoints: `GET /schedule/day`, `/schedule/week`, `/schedule/now`, exceptions PUT/DELETE, check-ins POST/DELETE, `copy-day`.
  3. Pest tests: weekday match, starts_on/ends_on, skip, override, now/next with "Free" and nothing-more-today, check-in toggle, time zone edge cases.
- **Done when:**
  - [ ] Tests and Pint pass
- **Files:** `app/Services/ScheduleService.php`, controllers, tests

### B-012: Streak API
- **Status:** done
- **Steps:**
  1. `StreakService` per docs/08 rule 8 (skip empty days, today is never judged, lazy recompute on check-off and `GET /streak`). Update users.current_streak, longest_streak, last_streak_date.
  2. Hook it into to-do and schedule check-offs. Pest tests for every rule, including time zones.
- **Done when:**
  - [ ] Tests and Pint pass
- **Files:** `app/Services/StreakService.php`, tests

### B-013: Guest data import
- **Status:** done
- **Steps:**
  1. `POST /import` per docs/08 rule 9: to-dos, schedule items, exceptions, check-ins with client UUIDs; idempotent; one transaction; enforce limits.
  2. Pest tests: success, repeat import creates no duplicates, invalid payload writes nothing, cannot overwrite another user's ids.
- **Done when:**
  - [ ] Tests and Pint pass
- **Files:** `ImportService`, controller, tests

### B-014: Feedback and account endpoints
- **Status:** done
- **Steps:**
  1. `POST /feedback` (stores in DB, emails FEEDBACK_TO_EMAIL; mail faked in tests), `PATCH /me`, `POST /me/survey`, `DELETE /me` (confirmation by password, cascades all rows).
  2. Pest tests for each.
- **Done when:**
  - [x] Tests and Pint pass; deleting a user leaves no rows behind (test proves it)
- **Files:** controllers, migrations, tests

### B-015: Google sign-in (code only)
- **Status:** done
- **Steps:**
  1. Socialite flow from docs/09: redirect (with `intent` sign_in or sign_up), callback, one-time code exchange. Errors `account_not_found` and `account_already_exists` per intent. Link google_id; never log tokens.
  2. Pest tests with a mocked Socialite provider. Real login testing is a separate step that needs Render secrets; do not ask for secrets here.
- **Done when:**
  - [x] Tests and Pint pass
- **Files:** `GoogleAuthController`, config/services.php, tests

### B-016: Production hardening (CORS, headers, config)
- **Status:** todo
- **Steps:**
  1. Publish and set `config/cors.php`: allow only the origin(s) in `FRONTEND_URL` (comma-separated allowed), `api/*` paths, bearer auth (no credentials), methods and headers needed. Pest test: allowed origin passes preflight, other origin does not.
  2. Force JSON for all API errors, hide stack traces in production (`APP_DEBUG=false` documented), add basic security headers middleware, trust Render proxy headers (HTTPS URLs correct).
  3. Make the health route also check the database connection (`/api/v1/health` stays public and cheap; add `?deep=1` for the DB check).
- **Done when:**
  - [ ] Tests and Pint pass; the CORS tests prove the allowed and blocked cases
- **Files:** `config/cors.php`, `bootstrap/app.php`, middleware, tests

### B-017: Static analysis and unused-code check
- **Status:** todo
- **Steps:**
  1. Add Larastan (PHPStan) at a sensible level and fix all findings. Remove unused code, imports, classes, translations and config the tool or a manual pass finds.
  2. Add it to CI.
- **Done when:**
  - [ ] CI runs Pint, PHPStan and tests green
- **Files:** `phpstan.neon`, `composer.json`, `.github/workflows/ci.yml`, various

### B-018: README and deployment guide
- **Status:** todo
- **Steps:**
  1. `README.md`: what Loomora is, stack, how to run locally (with and without Docker), how to run tests, environment variable table (names and meaning, no values), link to `docs/openapi.yaml`.
  2. `docs/DEPLOY.md`: exact click-by-click steps for Danish for Render (service `loomora-api`, Docker, env vars), Neon (direct connection string, US region), Brevo, Google redirect URIs (`https://<api-host>/api/v1/auth/google/callback`), and first-deploy checks. Write it for a student with no DevOps experience.
- **Done when:**
  - [ ] README and DEPLOY.md exist and match `render.yaml` and `.env.example`
- **Files:** `README.md`, `docs/DEPLOY.md`

## Done

_None yet._
