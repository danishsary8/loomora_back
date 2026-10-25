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
- **Status:** review
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
- **Status:** review
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
- **Status:** review
- **Steps:**
  1. Remove `package.json`, `vite.config.js`, `resources/js`, `resources/css` and the `welcome` view and web route. Keep mail views and API-only routing.
  2. Make sure `php artisan test` and Pint still pass.
- **Done when:**
  - [x] Tests and Pint pass; `GET /api/v1/health` still returns ok
- **Files:** `package.json`, `vite.config.js`, `resources/*`, `routes/web.php`

### B-008: Email verification and password reset (Brevo)
- **Status:** todo
- **Steps:**
  1. Endpoints from docs/09: email/verify, email/resend, password/forgot, password/reset. Registration sends the verification email.
  2. Mail through the Laravel mailer with an env-driven transport: `log` driver in local/test, Brevo (API or SMTP) in production. Env names only, no values. Plain, branded HTML mail templates (English, text via lang files).
  3. Pest tests with `Mail::fake()`: verify, resend (throttled), forgot, reset, expired/invalid token.
- **Done when:**
  - [ ] Tests and Pint pass; no secret in the repo
  - [ ] If a Brevo key is needed to test for real, mark that part `blocked` with the exact question
- **Files:** controllers, mail classes/templates, tests

### B-009: OpenAPI contract file
- **Status:** review
- **Steps:**
  1. Create `docs/openapi.yaml` for every endpoint built so far (health, auth, me, todos) with request/response examples and the error shape from docs/09.
  2. Add a short "keep in sync" note to CLAUDE.md. Document the current response shapes: login and register return `{token, expires_at, user}`; `/me` returns `{data: user}`.
- **Done when:**
  - [x] The file validates with an OpenAPI linter (e.g. `npx @redocly/cli lint docs/openapi.yaml`)
- **Files:** `docs/openapi.yaml`, `CLAUDE.md`

### B-010: Schedule items API
- **Status:** review
- **Steps:**
  1. Migrations, models, policies, controllers for `schedule_items` per docs/08 and docs/09 (create, list, edit all repeats, delete all repeats). Validate start < end, days non-empty, limit 100 per user.
  2. Pest tests including cross-user access denied.
- **Done when:**
  - [x] Tests and Pint pass
- **Files:** migrations, `ScheduleItem` model, controller, requests, resources, tests

### B-011: Schedule occurrences, exceptions, check-ins, now/next
- **Status:** review
- **Steps:**
  1. Build `ScheduleService` that returns occurrences for a date or a week (docs/08 rule 4), applying skip and override exceptions.
  2. Endpoints: `GET /schedule/day`, `/schedule/week`, `/schedule/now`, exceptions PUT/DELETE, check-ins POST/DELETE, `copy-day`.
  3. Pest tests: weekday match, starts_on/ends_on, skip, override, now/next with "Free" and nothing-more-today, check-in toggle, time zone edge cases.
- **Done when:**
  - [ ] Tests and Pint pass
- **Files:** `app/Services/ScheduleService.php`, controllers, tests

### B-012: Streak API
- **Status:** review
- **Steps:**
  1. `StreakService` per docs/08 rule 8 (skip empty days, today is never judged, lazy recompute on check-off and `GET /streak`). Update users.current_streak, longest_streak, last_streak_date.
  2. Hook it into to-do and schedule check-offs. Pest tests for every rule, including time zones.
- **Done when:**
  - [ ] Tests and Pint pass
- **Files:** `app/Services/StreakService.php`, tests

### B-013: Guest data import
- **Status:** review
- **Steps:**
  1. `POST /import` per docs/08 rule 9: to-dos, schedule items, exceptions, check-ins with client UUIDs; idempotent; one transaction; enforce limits.
  2. Pest tests: success, repeat import creates no duplicates, invalid payload writes nothing, cannot overwrite another user's ids.
- **Done when:**
  - [ ] Tests and Pint pass
- **Files:** `ImportService`, controller, tests

### B-014: Feedback and account endpoints
- **Status:** review
- **Steps:**
  1. `POST /feedback` (stores in DB, emails FEEDBACK_TO_EMAIL; mail faked in tests), `PATCH /me`, `POST /me/survey`, `DELETE /me` (confirmation by password, cascades all rows).
  2. Pest tests for each.
- **Done when:**
  - [x] Tests and Pint pass; deleting a user leaves no rows behind (test proves it)
- **Files:** controllers, migrations, tests

### B-015: Google sign-in (code only)
- **Status:** todo
- **Steps:**
  1. Socialite flow from docs/09: redirect (with `intent` sign_in or sign_up), callback, one-time code exchange. Errors `account_not_found` and `account_already_exists` per intent. Link google_id; never log tokens.
  2. Pest tests with a mocked Socialite provider. Real login testing is a separate step that needs Render secrets; do not ask for secrets here.
- **Done when:**
  - [ ] Tests and Pint pass
- **Files:** `GoogleAuthController`, config/services.php, tests

## Done

_None yet._
