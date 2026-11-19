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
- **Status:** done
- **Steps:**
  1. Publish and set `config/cors.php`: allow only the origin(s) in `FRONTEND_URL` (comma-separated allowed), `api/*` paths, bearer auth (no credentials), methods and headers needed. Pest test: allowed origin passes preflight, other origin does not.
  2. Force JSON for all API errors, hide stack traces in production (`APP_DEBUG=false` documented), add basic security headers middleware, trust Render proxy headers (HTTPS URLs correct).
  3. Make the health route also check the database connection (`/api/v1/health` stays public and cheap; add `?deep=1` for the DB check).
- **Done when:**
  - [x] Tests and Pint pass; the CORS tests prove the allowed and blocked cases
- **Files:** `config/cors.php`, `bootstrap/app.php`, middleware, tests

### B-017: Static analysis and unused-code check
- **Status:** done
- **Steps:**
  1. Add Larastan (PHPStan) at a sensible level and fix all findings. Remove unused code, imports, classes, translations and config the tool or a manual pass finds.
  2. Add it to CI.
- **Done when:**
  - [x] CI runs Pint, PHPStan and tests green
- **Files:** `phpstan.neon`, `composer.json`, `.github/workflows/ci.yml`, various

### B-018: README and deployment guide
- **Status:** done
- **Steps:**
  1. `README.md`: what Loomora is, stack, how to run locally (with and without Docker), how to run tests, environment variable table (names and meaning, no values), link to `docs/openapi.yaml`.
  2. `docs/DEPLOY.md`: exact click-by-click steps for Danish for Render (service `loomora-api`, Docker, env vars), Neon (direct connection string, US region), Brevo, Google redirect URIs (`https://<api-host>/api/v1/auth/google/callback`), and first-deploy checks. Write it for a student with no DevOps experience.
- **Done when:**
  - [x] README and DEPLOY.md exist and match `render.yaml` and `.env.example`
- **Files:** `README.md`, `docs/DEPLOY.md`

## Done

_None yet._

## R1 leftovers and R2 (added 2026-11-19 from the Daycraft analysis)
Source: `docs/research/daycraft-gap-analysis.md` and `docs/research/daycraft-task-prompts.md` (behaviour reference only; never copy Daycraft code or visuals). Every task updates `docs/openapi.yaml` and runs `npx @redocly/cli lint docs/openapi.yaml`. New to-do fields must also be accepted by `POST /import`.

### B-019: Subtasks, priority and notes on to-dos
- **Status:** done
- **Steps:**
  1. Migration on `todos`: `notes` text null (max 2000), `priority` enum `low|normal|high` default `normal`, `parent_id` uuid null (foreign key to `todos`, same user).
  2. Rules: a subtask's parent must be the same user's and must itself have no parent (depth 1 only); a subtask cannot have `repeat_rule` or `remind_at`. Checking a parent marks its open subtasks done in the same transaction; unchecking a parent leaves them. Subtask check-offs count for the streak (record in `docs/DECISIONS.md`).
  3. List and single responses return top-level to-dos with a nested `subtasks` array (ordered by creation); update the Todo resources, requests and `docs/openapi.yaml`. Extend `POST /import` to accept the new fields.
  4. Pest feature tests for each rule above, plus a 422 for a subtask of a subtask and a 404 for another user's `parent_id`.
- **Done when:**
  - [x] `php artisan test` passes the new tests
  - [x] `./vendor/bin/pint --test` and the static-analysis command from B-017 pass
  - [x] `npx @redocly/cli lint docs/openapi.yaml` is valid
- **Files:** `database/migrations/*`, `app/Models/Todo.php`, todo requests and resources, `app/Services/*Import*`, `tests/Feature/*`, `docs/openapi.yaml`

### B-020: Repeating to-dos
- **Status:** done
- **Steps:**
  1. Migration: `todos.repeat_rule` jsonb null (`{type:'daily'}` or `{type:'weekly', days:[...]}`; use the same weekday representation as schedule items) and `todos.next_todo_id` uuid null. A repeating to-do requires a `due_date` (422 otherwise).
  2. When a repeating to-do is checked off the first time, create the next one in the same transaction: same title, category, priority, notes, rule and a copy of its subtasks (undone); `due_date` is the next matching date after the later of the old due date and today in the user's time zone. Save its id in `next_todo_id`. Unchecking and checking again must not create another.
  3. Update resources, requests, `docs/openapi.yaml` and `POST /import`. Add the rule to `docs/DECISIONS.md`.
- **Done when:**
  - [x] Tests pass for: daily, weekly across a week boundary, a check-off at 00:30 in `Asia/Phnom_Penh` (UTC date is still the day before), toggling twice creates one next to-do, missing `due_date` returns 422, subtasks are copied undone
  - [x] Pint, static analysis and Redocly lint pass
- **Files:** migration, `app/Models/Todo.php`, `app/Services/RepeatTodoService.php`, todo controller, `tests/Feature/RepeatTodoTest.php`, `docs/openapi.yaml`

### B-021: In-app reminders for to-dos
- **Status:** review
- **Steps:**
  1. Migration: `todos.remind_at` timestamptz null (stored UTC), `todos.remind_dismissed_at` timestamptz null. Not allowed on subtasks.
  2. `GET /reminders/due` returns the user's undone, non-deleted to-dos where `remind_at <= now` and not dismissed, oldest first (max 50). `POST /todos/{todo}/reminder/dismiss` sets `remind_dismissed_at`. `POST /todos/{todo}/reminder/snooze {minutes: 5|10|30|60}` sets `remind_at = now + minutes` and clears the dismissal. Changing `remind_at` clears the dismissal.
  3. No push notifications (docs/02: Later). Update `docs/openapi.yaml` and `POST /import`.
- **Done when:**
  - [x] Tests pass for: due list contents and order, done and deleted to-dos excluded, dismiss, snooze values (other values return 422), another user's to-do returns 404
  - [x] Pint, static analysis and Redocly lint pass
- **Files:** migration, `app/Http/Controllers/*Reminder*`, `routes/api.php`, `tests/Feature/ReminderTest.php`, `docs/openapi.yaml`

### B-022: To-do soft delete and restore
- **Status:** review
- **Steps:**
  1. Add soft deletes to `todos`. `DELETE /todos/{todo}` soft-deletes (204) and also soft-deletes its subtasks. `POST /todos/{todo}/restore` returns the to-do (and its subtasks) if it was deleted less than 7 days ago, otherwise 410.
  2. Do NOT add any command that permanently deletes expired rows. Write the question in `docs/PROGRESS.md`: should a daily command hard-delete to-dos deleted more than 7 days ago (planner decides, it is destructive)?
  3. Check that account deletion still removes all of the user's to-dos including soft-deleted ones. Update `docs/openapi.yaml`.
- **Done when:**
  - [ ] Tests pass for: delete hides it from lists, restore brings it back with the same id, restore after 8 days returns 410, another user returns 404, account deletion leaves no rows
  - [ ] Pint, static analysis and Redocly lint pass
- **Files:** migration, `app/Models/Todo.php`, todo controller, `routes/api.php`, `tests/Feature/TodoRestoreTest.php`, `docs/openapi.yaml`

### B-023: Time format and week start preferences
- **Status:** review
- **Steps:**
  1. Migration on `users`: `time_format` enum `12h|24h` default `24h`, `week_starts_on` smallint 1 (Monday) to 7 (Sunday) default 1. Allow them in `PATCH /me` and return them in the User resource; `timezone` keeps its current validation (use the PHP identifier list if not already).
  2. Add `tests/Fixtures/local-date-cases.json` with the cases from DC-10 in `docs/research/daycraft-task-prompts.md` (Asia/Phnom_Penh just after local midnight, Asia/Kolkata, America/Los_Angeles, a DST day) and a test that reads it against the existing local-date helper. Fix the helper if a case fails.
  3. Update `docs/openapi.yaml`.
- **Done when:**
  - [ ] Tests pass for valid and invalid values (`time_format: 'x'` and `week_starts_on: 8` return 422) and for every fixture case
  - [ ] Pint, static analysis and Redocly lint pass
- **Files:** migration, `UpdateMeRequest`, user resource, `tests/Fixtures/local-date-cases.json`, `tests/Feature/*`, `docs/openapi.yaml`

### B-024: Daily intention API
- **Status:** todo
- **Steps:**
  1. Table `daily_intentions`: `user_id`, `local_date` date, `text` string(200), timestamps, unique `(user_id, local_date)`.
  2. `GET /days/{date}/intention` returns `{data:{date, text}}` (`text` null if none). `PUT` the same path with `{text}` upserts; an empty string deletes the row. `{date}` must be `Y-m-d` within 366 days of the user's today (else 422).
  3. Extend `POST /import` with `daily_intentions` (idempotent). Update `docs/openapi.yaml`.
- **Done when:**
  - [ ] Tests pass for: put then get, second put updates the same row, empty deletes, invalid date returns 422, another user cannot see it, import twice leaves one row
  - [ ] Pint, static analysis and Redocly lint pass
- **Files:** migration, `app/Models/DailyIntention.php`, controller, `routes/api.php`, `tests/Feature/DailyIntentionTest.php`, `docs/openapi.yaml`

### B-025: `has_password` on the user
- **Status:** todo
- **Steps:**
  1. Return `has_password` (boolean) in the User resource, true when the account has a password (false for Google-only accounts). Update `docs/openapi.yaml`.
- **Done when:**
  - [ ] Tests pass for an email account (true) and a Google-only account (false)
  - [ ] Pint, static analysis and Redocly lint pass
- **Files:** `app/Http/Resources/UserResource.php`, `tests/Feature/*`, `docs/openapi.yaml`
