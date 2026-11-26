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
  - [x] The workflow runs green on the PR
- **Files:** `.github/workflows/ci.yml`

### B-004: Dockerfile and Render blueprint
- **Status:** done
- **Steps:**
  1. Add a production `Dockerfile` (PHP 8.3 + Apache or php-fpm + nginx) that runs migrations on start with `php artisan migrate --force` and fails loudly if they fail.
  2. Add `render.yaml` for a service named `loomora-api` (free plan, Docker, health check `/api/v1/health`), listing env variable NAMES only.
  3. Do not deploy. Do not add secrets.
- **Done when:**
  - [x] `docker build .` succeeds
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
  - [x] Tests and Pint pass
- **Files:** `app/Services/ScheduleService.php`, controllers, tests

### B-012: Streak API
- **Status:** done
- **Steps:**
  1. `StreakService` per docs/08 rule 8 (skip empty days, today is never judged, lazy recompute on check-off and `GET /streak`). Update users.current_streak, longest_streak, last_streak_date.
  2. Hook it into to-do and schedule check-offs. Pest tests for every rule, including time zones.
- **Done when:**
  - [x] Tests and Pint pass
- **Files:** `app/Services/StreakService.php`, tests

### B-013: Guest data import
- **Status:** done
- **Steps:**
  1. `POST /import` per docs/08 rule 9: to-dos, schedule items, exceptions, check-ins with client UUIDs; idempotent; one transaction; enforce limits.
  2. Pest tests: success, repeat import creates no duplicates, invalid payload writes nothing, cannot overwrite another user's ids.
- **Done when:**
  - [x] Tests and Pint pass
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
- **Status:** done
- **Steps:**
  1. Migration: `todos.remind_at` timestamptz null (stored UTC), `todos.remind_dismissed_at` timestamptz null. Not allowed on subtasks.
  2. `GET /reminders/due` returns the user's undone, non-deleted to-dos where `remind_at <= now` and not dismissed, oldest first (max 50). `POST /todos/{todo}/reminder/dismiss` sets `remind_dismissed_at`. `POST /todos/{todo}/reminder/snooze {minutes: 5|10|30|60}` sets `remind_at = now + minutes` and clears the dismissal. Changing `remind_at` clears the dismissal.
  3. No push notifications (docs/02: Later). Update `docs/openapi.yaml` and `POST /import`.
- **Done when:**
  - [x] Tests pass for: due list contents and order, done and deleted to-dos excluded, dismiss, snooze values (other values return 422), another user's to-do returns 404
  - [x] Pint, static analysis and Redocly lint pass
- **Files:** migration, `app/Http/Controllers/*Reminder*`, `routes/api.php`, `tests/Feature/ReminderTest.php`, `docs/openapi.yaml`

### B-022: To-do soft delete and restore
- **Status:** done
- **Steps:**
  1. Add soft deletes to `todos`. `DELETE /todos/{todo}` soft-deletes (204) and also soft-deletes its subtasks. `POST /todos/{todo}/restore` returns the to-do (and its subtasks) if it was deleted less than 7 days ago, otherwise 410.
  2. Do NOT add any command that permanently deletes expired rows. Write the question in `docs/PROGRESS.md`: should a daily command hard-delete to-dos deleted more than 7 days ago (planner decides, it is destructive)?
  3. Check that account deletion still removes all of the user's to-dos including soft-deleted ones. Update `docs/openapi.yaml`.
- **Done when:**
  - [x] Tests pass for: delete hides it from lists, restore brings it back with the same id, restore after 8 days returns 410, another user returns 404, account deletion leaves no rows
  - [ ] Pint, static analysis and Redocly lint pass
- **Files:** migration, `app/Models/Todo.php`, todo controller, `routes/api.php`, `tests/Feature/TodoRestoreTest.php`, `docs/openapi.yaml`

### B-023: Time format and week start preferences
- **Status:** done
- **Steps:**
  1. Migration on `users`: `time_format` enum `12h|24h` default `24h`, `week_starts_on` smallint 1 (Monday) to 7 (Sunday) default 1. Allow them in `PATCH /me` and return them in the User resource; `timezone` keeps its current validation (use the PHP identifier list if not already).
  2. (Moved to B-027, see Q20.) Add `tests/Fixtures/local-date-cases.json` with the cases from DC-10 in `docs/research/daycraft-task-prompts.md` (Asia/Phnom_Penh just after local midnight, Asia/Kolkata, America/Los_Angeles, a DST day) and a test that reads it against the existing local-date helper. Fix the helper if a case fails.
  3. Update `docs/openapi.yaml`.
- **Done when:**
  - [x] Tests pass for valid and invalid values (`time_format: 'x'` and `week_starts_on: 8` return 422) and for every fixture case
  - [ ] Pint, static analysis and Redocly lint pass
- **Files:** migration, `UpdateMeRequest`, user resource, `tests/Fixtures/local-date-cases.json`, `tests/Feature/*`, `docs/openapi.yaml`

### B-024: Daily intention API
- **Status:** done
- **Steps:**
  1. Table `daily_intentions`: `user_id`, `local_date` date, `text` string(200), timestamps, unique `(user_id, local_date)`.
  2. `GET /days/{date}/intention` returns `{data:{date, text}}` (`text` null if none). `PUT` the same path with `{text}` upserts; an empty string deletes the row. `{date}` must be `Y-m-d` within 366 days of the user's today (else 422).
  3. Extend `POST /import` with `daily_intentions` (idempotent). Update `docs/openapi.yaml`.
- **Done when:**
  - [x] Tests pass for: put then get, second put updates the same row, empty deletes, invalid date returns 422, another user cannot see it, import twice leaves one row
  - [ ] Pint, static analysis and Redocly lint pass
- **Files:** migration, `app/Models/DailyIntention.php`, controller, `routes/api.php`, `tests/Feature/DailyIntentionTest.php`, `docs/openapi.yaml`

### B-025: `has_password` on the user
- **Status:** done
- **Steps:**
  1. Return `has_password` (boolean) in the User resource, true when the account has a password (false for Google-only accounts). Update `docs/openapi.yaml`.
- **Done when:**
  - [x] Tests pass for an email account (true) and a Google-only account (false)
  - [ ] Pint, static analysis and Redocly lint pass
- **Files:** `app/Http/Resources/UserResource.php`, `tests/Feature/*`, `docs/openapi.yaml`

### B-026: Repeating to-do keeps its reminder time
- **Status:** done
- **Steps:**
  1. When the first check-off of a repeating to-do creates the next to-do, move `remind_at` by the same time distance as the due date moved (old due date to new due date), so "remind me at 07:00" repeats. If the old to-do has no `remind_at`, the next one has none. Subtasks copied with it get no reminder (subtasks cannot have one).
  2. Tests: daily and weekly repeats, a reminder 1 hour before a 09:00 due time, no reminder, the 00:30 Asia/Phnom_Penh case. Update DECISIONS I7 and `docs/openapi.yaml` text if it mentions "not copied".
- **Done when:**
  - [x] The next to-do's `remind_at` keeps the same distance from its due date, in UTC
  - [ ] Pest, Pint, PHPStan and Redocly lint pass
- **Files:** `app/Services/RepeatTodoService.php`, `tests/Feature/RepeatTodoTest.php`, `docs/DECISIONS.md`, `docs/openapi.yaml`

### B-027: Local-date helper and fixture cases
- **Status:** done
- **Steps:**
  1. Add `App\Support\LocalDate` as described in DC-10 of `docs/research/daycraft-task-prompts.md` (`forUser`, `todayFor`, `rangeUtc`).
  2. Replace the inline local-date code with it in `StreakService`, `TodayRequest::localDate()`, `RepeatTodoService`, `IntentionDateRequest` and any other place `CarbonImmutable::now($user->timezone)` is used. No other behaviour change.
  3. Add `tests/Fixtures/local-date-cases.json` (Asia/Phnom_Penh just after local midnight, Asia/Kolkata, America/Los_Angeles, a DST day) and a test that reads it.
- **Done when:**
  - [x] Every fixture case passes and no inline local-date code is left
  - [ ] All earlier tests still pass; Pint, PHPStan and Redocly lint pass
- **Files:** `app/Support/LocalDate.php`, the services and requests above, `tests/Fixtures/local-date-cases.json`, `tests/Feature/*`

### B-028: Account security and deletion integrity
- **Status:** done
- Merged into `v2-rebuild` at `5f0be5e` in [PR #31](https://github.com/danishsary8/loomora_back/pull/31).
- **Scope:** QA-001 / QA-004 follow-up. Verify password confirmation, deletion rollback and cascades, bearer token revocation, resource ownership and API error responses. Preserve the Google-only deletion restriction pending Q23.
- **Done when:**
  - [x] Regression coverage checks failed deletion, successful deletion and authorization with real bearer tokens on disposable data.
  - [x] OpenAPI and the API summary describe the deletion behavior and the Google-only restriction.
  - [x] PostgreSQL and SQLite Pest suites, Pint, PHPStan, unused-code review, Redocly and Docker results are recorded.
- **Files:** account deletion request and model, `tests/Feature/FeedbackAndAccountTest.php`, and API/task/progress docs.

### B-029: Patch backend runtime dependencies
- **Status:** done
- Merged into `v2-rebuild` at `926a15a` in [PR #32](https://github.com/danishsary8/loomora_back/pull/32).
- **Audit:** QA-001 / QA-005. On the updated `v2-rebuild` at `5f0be5e`, `composer audit --locked --no-dev` reported 11 runtime advisories across seven packages.
- **Triage:** QA-002 requires frontend routing and deployment work. QA-003 requires frontend guest persistence. QA-004 requires frontend disclosures and a planner decision on Google-only deletion (Q23). Password deletion integrity shipped in B-028 ([PR #31](https://github.com/danishsary8/loomora_back/pull/31)). QA-009 is the staged release sign-off gate.
- **Plan:**
  - Confirm each affected package and its first patched version in the supported release line.
  - Add a deterministic Pest regression for the production lock and verify that the base versions fail.
  - Update the affected Composer packages within the current PHP, Laravel and Symfony release lines.
  - Rerun the production audit, database tests, code checks and Docker smoke tests. Record the results for planner review.
- **Done when:**
  - [x] The Pest lockfile regression fails on the original lock and passes with all affected packages patched.
  - [x] `composer audit --locked --no-dev` reports zero advisories and the production Docker image installs the patched lock and boots on PHP 8.3.
  - [x] PostgreSQL, SQLite, Pint, Larastan, the B-017 unused-code review, Redocly and Docker smoke tests pass. Existing API contracts and ownership tests pass.
- **Scope:** Backend production Composer dependencies. Frontend toolchain and browser hardening remain separate QA-005 work.

### B-030: Patch the backend development dependency advisory
- **Status:** done
- Merged into `v2-rebuild` at `199ec25` in [PR #33](https://github.com/danishsary8/loomora_back/pull/33).
- **Scope:** QA-001 / QA-005 follow-up for development-only `symfony/yaml` [GHSA-hwfg-qwvh-2wfx](https://github.com/symfony/symfony/security/advisories/GHSA-hwfg-qwvh-2wfx).
- **Done when**
  - [x] The Pest lockfile regression fails on v7.4.21 and passes after a targeted update to v7.4.23.
  - [x] Both locked Composer audits report zero advisories and strict validation passes.
  - [x] PostgreSQL and SQLite Pest suites, Pint, Larastan, unused-code review, Redocly lint and Docker deep health are verified.

### B-031: Backend security and secrets audit
- **Status:** done
- Merged into `v2-rebuild` at `2db49f9` in [PR #34](https://github.com/danishsary8/loomora_back/pull/34).
- **Scope:** Remaining backend QA-005 checks from QA-001. Evidence and production blockers are recorded in the B-031 progress entry.
- **Done when**
  - [x] Redacted scans cover tracked files and locally reachable Git history, including fetched upstream branches.
  - [x] Regression checks cover ignored environment files and security headers on HTTPS preflights. Debug-off errors, authorization and the existing API contract are verified in disposable environments.
  - [x] Both locked Composer audits, strict validation, PostgreSQL and SQLite Pest, Pint, Larastan, unused-code review, Redocly and Docker deep health are recorded.
- **Deployment follow-up:** B-032 records the Q24 decision and tracks owner verification of the live configuration, Q25 and the unresolved Q23 release decision.

### B-032: Staging integration and release readiness
- **Status:** done
- Merged into `v2-rebuild` at `65d29e4` in [PR #35](https://github.com/danishsary8/loomora_back/pull/35).
- **Scope:** Compare the public API and frontend with the fetched source candidates. Record the local verification, blocked staging checks and redacted owner evidence needed for QA-009.
- [x] Verify the B-031 merge, public API health and headers, the Vercel alias commit and the frontend/backend contract.
- [x] Record local test and container results, update the Q24 deployment guidance and document Q23 and Q25 in the [B-032 assessment](PROGRESS.md#2026-11-20-b-032-staging-integration-and-release-readiness).
- **QA-009 follow-up:** Sign-off requires an approved disposable frontend/API/database pair, exact deployed SHAs and owner evidence for Render, Neon, Google and Brevo.
- **Release recommendation:** NO-GO. Public app and auth deep links fail, the Render SHA and paired staging flows lack verification, and the planner must resolve Q23. QA-005 and QA-009 remain open.

### B-033: Canonical frontend origin validation
- **Status:** done
- Merged into `v2-rebuild` at `bb19bc0` in [PR #36](https://github.com/danishsary8/loomora_back/pull/36).
- **Scope:** Validate one `FRONTEND_URL` at boot and use it for CORS, Google callback redirects, email verification and password reset.
- **Done when:**
  - [x] Regression tests cover invalid configuration, approved and unrelated origins, auth links, localhost and the configuration cache.
  - [x] PostgreSQL, SQLite, both locked Composer audits, strict validation, Pint, Larastan, unused-code review, Redocly and Docker smoke results are recorded in the [B-033 progress entry](PROGRESS.md#2026-11-20-b-033-canonical-frontend-origin-validation).
- **Deployment follow-up:** Q25 owner confirmation and QA-009 staging sign-off are pending. Q23 remains open.

### B-034: Google-only account deletion security design
- **Status:** done ([PR #37](https://github.com/danishsary8/loomora_back/pull/37), merged at `e6e705fc1db5d513394a70a34f81ec66be9f33c1`)
- **Scope:** Q23 and QA-004. `DELETE /me` requires a current password and returns 422 for Google-only accounts without one. The [design proposal](B-034-google-account-deletion-design.md) compares Google reauthentication, verified email confirmation and restricting Google-only signup at launch. It specifies the recommended API, frontend integration and regression plan.
- **Recommendation:** Use a purpose-bound Google confirmation with a signed recent authentication time and the original Sanctum session. Pending the provider capability check, keep Google-only signup outside public launch if the team cannot establish the required freshness.
- **Planner decision:** Q23 remains open. B-035 adds evidence for an existing recovery path alongside the B-034 options. QA-004 disclosures, Q25 owner evidence and QA-009 staging sign-off remain pending.

### B-035: Google-only account recovery and deletion readiness
- **Status:** done ([PR #38](https://github.com/danishsary8/loomora_back/pull/38), merged at `d9556d4203ced6f3b4c481338a954b959e153343`)
- **Scope:** Planner-authorized follow-up to B-034 / Q23. Verify whether deliberate password recovery followed by password sign-in and explicit account deletion can use the existing API.
- [x] Exercise the sequential flow with a real broker token and Sanctum bearers, including recovery rejection, identity preservation, revocation, deletion, isolation and database rollback.
- [x] Review security and document the [frontend handoff](B-034-google-account-deletion-design.md#frontend-handoff-after-approval) and [verification](PROGRESS.md#2026-11-20-b-035-google-only-account-recovery-and-deletion-readiness).
- **Release gate:** The [B-035 assessment](B-034-google-account-deletion-design.md#b-035-addendum-existing-password-recovery) remains under Q23. B-036 addresses the partial commit and records the remaining credential-lifecycle work.

### B-036: Password reset atomicity and credential safety
- **Status:** done ([PR #39](https://github.com/danishsary8/loomora_back/pull/39), merged at `24d4e7225fe212dfe73e41be9827e8509fe625ac`)
- **Scope:** Make Laravel's database-backed token redemption, password save, Sanctum bearer revocation and reset-token consumption one transaction. Serialize redemption, password login, Google exchange and account deletion on the existing user row.
- [x] Commit a RED regression and prove rollback, reset-token retry and another account's isolation on PostgreSQL and SQLite.
- [x] Verify overlapping reset, sign-in, exchange and deletion with independent PostgreSQL connections. See the [B-036 verification](PROGRESS.md#2026-11-20-b-036-password-reset-atomicity-and-credential-safety).
- **Release gate:** Q23 still requires planner approval of the recovery assurance model and paired staging verification. Q25 and QA-009 remain open.

### B-037: Credential lifecycle across authentication boundaries
- **Status:** done ([PR #40](https://github.com/danishsary8/loomora_back/pull/40), merged at `1188595d4aa0b3c5ab2638b4ee6db0d2829640e7`)
- **Scope:** Define how reset and deletion invalidate outstanding Google exchange grants. Coordinate reset-link issuance, Google callback/linking and registration with account lifecycle changes. Define the guarantee for protected requests that passed Sanctum authentication before revocation, and enforce it for any operations that must recheck authorization before committing.
- **Acceptance:** Verify ordering with separate PostgreSQL connections. Cover stale grants and fresh Google sign-in, revoked bearers, superseded recovery credentials, account deletion and re-registration, and unrelated-account isolation.
- **Verification:** Full PostgreSQL and SQLite suites, the dedicated PostgreSQL concurrency suite, static checks and the disposable production image smoke passed.
- **Planner decision:** Review the scope of invalidation for an OAuth flow that has yet to resolve a Loomora account. Q23, Q25 and QA-009 remain open. [B-037 findings and verification](PROGRESS.md#2026-11-21-b-037-credential-lifecycle-across-authentication-boundaries)

### B-038: Credential cutover and rollback readiness
- **Status:** done ([PR #41](https://github.com/danishsary8/loomora_back/pull/41), merged at `2ce5deac64f14189b53fa16751d82ba1ba2da353`)
- **Scope:** Rehearse the B-036 to B-037 upgrade on disposable PostgreSQL 16 and document data preservation, credential compatibility, release ordering and recovery.
- **Verification:** The two-image rehearsal preserved the populated baseline and established how old bearers, recovery links and Google grants behave on B-037. See the [operator runbook](B-038-credential-cutover-runbook.md) and [test results](PROGRESS.md#2026-11-21-b-038-credential-cutover-and-rollback-readiness).
- **Release gate:** Owner evidence for traffic fencing, backup restoration, historical reset-link treatment, Q23, Q25 and QA-009 remains open.

### B-039: Backend staging integration and API contract verification

- **Status:** done ([PR #42](https://github.com/danishsary8/loomora_back/pull/42), merged at `6df3ed9a6610d915a9d9128d5359eaed08691b3f`)
- **Scope:** Verify the B-037 candidate on a production-style API and disposable PostgreSQL 16. Compare the fetched frontend OpenAPI snapshot and record owner-only release gates.
- [x] Run actual HTTP checks of auth, account lifecycle, planner workflows, throttling, CORS, and database outage and recovery.
- [x] Compare 32 paths and 42 operations and document the frontend snapshot drift.
- [x] Record both database suites, PostgreSQL concurrency, static checks, locked audits, Redocly and Docker setup guards in the [integration matrix](B-039-backend-integration-matrix.md).
- **Release gate:** Q23, Q25 and QA-009 remain open. Local verification is ready for planner review.

### B-040: Backend release audit and R1 handoff
- **Status:** done ([PR #43](https://github.com/danishsary8/loomora_back/pull/43), merged at `7ff0b4c66985a8eb4f94292ebe2941eea7fc00da`)
- **Scope:** Audit the merged R1 backend and assess the merged B-038 and B-039 evidence. Reconcile the task queue and record security, cutover, operator and staging evidence.
- [x] Run full PostgreSQL and SQLite suites, static and dependency checks, OpenAPI lint and disposable Docker verification.
- [x] Record known findings, integration order and owner-only gates in the [release audit](B-040-backend-release-readiness.md).
- **Release recommendation:** NO-GO pending Q23, Q25, QA-009, verified log handling and owner-approved cutover and rollback. B-038 and B-039 are merged.

### B-041: Sensitive access-log redaction

- **Status:** done ([PR #44](https://github.com/danishsary8/loomora_back/pull/44), merged at `f87bf1b57a6427c845f9310778be538ac83bd0ee`)
- **Scope:** Ship one Apache access log for the production container with timestamp, method, query-free path, final status and response body size. Keep Apache diagnostics on stderr and omit the raw Referer suffix.
- [x] Reproduce the baseline exposure with synthetic markers and verify the same HTTP smoke test against the repaired image.
- [x] Add the access-log smoke to the Docker CI job and document the container's logging behavior and limits.
- **Release gate:** Q23, Q25 and QA-009 remain open. Render and proxy log collection, access permissions and retention require redacted owner evidence under Q25. See the [verification record](PROGRESS.md#2026-11-21-b-041-sensitive-access-log-redaction).

### B-042: Google-only account deletion

- **Status:** done ([PR #45](https://github.com/danishsary8/loomora_back/pull/45), merged at `c9a7acf0e03a0a3ee5f60cc38198634fd0f58e3d`)
- **Scope:** Implement the planner-approved [B-034 Option A](B-034-google-account-deletion-design.md#proposed-option-a-contract). Bind the signed Google authentication time to a single-use deletion challenge and reuse B-037 account locking and revocation.
- **Backend:** Challenge and verify endpoints and explicit Google confirmation on DELETE /me. Password confirmation remains required for accounts with a password. The Google flow is off by default.
- **Release gate:** Google documents signed session-age claims for eligible configured clients. The Loomora staging client, dedicated callback, paired frontend, and fresh-versus-stale provider evidence are pending under Q23, Q25 and QA-009. See the [B-042 verification record](PROGRESS.md#2026-11-22-b-042-google-only-account-deletion).

### B-043: R3 Focus session API contract and persistence

- **Status:** done ([PR #47](https://github.com/danishsary8/loomora_back/pull/47), merged at `2dfc976f8d57f4933fd0bbbfcc5653bd4a6498cb`; [PR #46](https://github.com/danishsary8/loomora_back/pull/46) merged the design proposal at `33338a034f91d957362ab968c424d4eda677d7f6`)
- **Scope:** Store each finished Focus session (countdown with break cycles, or stopwatch with laps) once, linked to an optional to-do or schedule item, with client UUIDs and idempotent retries. The browser timer engine and UI are out of scope. The contract is in the [B-043 design](B-043-focus-session-design.md) and `openapi.yaml`.
- [x] Inspect existing structures and write the contract proposal.
- [x] Planner approved Q-B043-0 to Q-B043-6 with amendments (2026-11-23): no 60 s minimum, guest import deferred.
- [x] Migration, model, request, service, controller, resource, routes, Pest tests (SQLite and PostgreSQL, including a real race) and `openapi.yaml`.
- [x] Clock policy correction: sessions are never rejected against the server clock. Approved together with exact segment durations (2026-11-23).
- [x] Planner review of PR #47.
- **Note:** the frontend repository's provisional "B-043" for R2 import capacity is renumbered B-044 by the planner.

### B-044: Lossless guest-to-account import

- **Status:** done ([PR #48](https://github.com/danishsary8/loomora_back/pull/48), merged at `13c64ff988ea1d6f63acc9e0ecb8f04201d8afc5`; planner decisions Q-B044-1 to 4 approved 2026-11-24; see [implementation](PROGRESS.md#2026-11-24-b-044-lossless-guest-import-implementation))
- **Scope:** `POST /import` must accept a full guest plan whose repeat-generated to-dos take it past 500 rows. Keep client UUIDs, every to-do field, repeat rules and links, and parent/subtask links. Retries must not create duplicates, account isolation must hold, and nothing may be silently skipped. The 500-row manual-creation limit stays. This replaces the frontend's provisional "B-043" (QA-023).
- [x] Find the root cause and compare current behavior with the documented rules.
- [x] Planner decision on Q-B044-1 to Q-B044-4.
- [x] Implementation (DECISIONS I9), Pest tests (499/500/501/>500 repeat-generated rows, existing data, retries, rollback, links, cross-account ids), docs/08 rules 9 and 11 and `openapi.yaml`.
- [x] Subtask provenance (all subtasks of a successor are exempt) approved as a schema limitation (2026-11-24).
- [x] Final review follow-up: corrected chunk-order contract, checked against the real frontend, regression tests added.
- [x] Planner review of PR #48.
- **Frontend follow-up required:** the current `saveGuestPlan.ts` sends every to-do in the first request. A plan of more than 500 to-dos (for example 500 manual plus any generated) is refused by the 500-row request cap. It must split to-dos into requests of at most 500 in the order in `openapi.yaml` (`POST /import`) and keep the guest copy until every request succeeds. See the [follow-up entry](PROGRESS.md#2026-11-24-b-044-final-review-follow-up).
- **Out of scope:** B-043 Focus API, guest Focus history import, R4 analytics, frontend changes.

### B-045: Focus session label up to 200 characters

- **Status:** done ([PR #50](https://github.com/danishsary8/loomora_back/pull/50), merged at `4ef99b25e5d59d230bfaa631c7ae5e075701d64b`)
- **Owner:** claude
- **Scope:** Raise the Focus session `label` limit from 120 to 200 characters, so a to-do or schedule item title (up to 200) can be stored as the session label without truncation. This unblocks the frontend F-R3-10. Owner-approved 2026-11-25 (spec S1, decision D1). The contract change is additive: requests and responses keep their shape, and existing labels are unchanged. Running the migration in production is an owner deploy step and is not part of this task.
- **Plan:**
  1. Pest tests first: a 200-character label (multibyte and emoji at the boundary) is stored exactly, 201 is a 422 on `label`, an existing 120-character row stays readable, and an identical retry still returns 200.
  2. `FocusSession::MAX_LABEL_LENGTH = 200`, and pin the original create migration to its literal `120` so the migration history stays fixed.
  3. A new migration widens `focus_sessions.label` to `varchar(200)`. Its `down()` refuses with a clear error while any label is longer than 120, so nothing is truncated.
  4. Update `docs/openapi.yaml` and the B-043 design (marked as amended by B-045).
  5. Run Pest on SQLite and PostgreSQL, Pint, PHPStan, Redocly and `composer audit`, plus migrate and rollback on a fresh and a seeded PostgreSQL database.
- [x] A 200-character label is stored exactly, a 201-character label is a 422 on `label`, and nothing is truncated.
- [x] The migration widens the column, and its rollback refuses while any label is longer than 120.
- [x] Pest (SQLite and PostgreSQL), Pint, PHPStan, Redocly lint and `composer audit` pass.
- **Out of scope:** frontend F-R3-10, production migration and deploy.

### B-046: Focus session soft delete and restore

- **Status:** done ([PR #54](https://github.com/danishsary8/loomora_back/pull/54), merged at `6a8fb15bb9d75956a9c50851868ee32a5cf5ebc1`)
- **Owner:** Codex
- **Scope:** Let a user delete a finished Focus session and undo it, mirroring the to-do soft delete (B-022). Owner-approved 2026-11-25 (spec S2, decisions D3 to D5). `DELETE /focus-sessions/{id}` returns 204 and is idempotent. `POST /focus-sessions/{id}/restore` returns the session if it was deleted less than 7 days ago, otherwise 410, and returns a session that is not deleted unchanged. Deleted sessions are hidden from list and show. An identical replay of a deleted session's UUID returns 200 and the session stays deleted (D3); a changed payload is still a 409. No cascade from to-do or schedule item deletes (D5), and no purge command. The frontend F-R3-14 follows after this merges. Running the migration in production is an owner deploy step.
- **Plan:**
  1. Pest tests first for delete, restore, the 7-day window, cross-user 404, replay after delete (D3), conflict after delete and account deletion.
  2. A migration adds `deleted_at` (millisecond precision). Its `down()` refuses while any session is deleted, so deletion state is never silently lost.
  3. `FocusSession` uses `SoftDeletes`. The replay path reads with `withTrashed()`, both before the insert and after the `ON CONFLICT` re-read.
  4. Add the delete and restore endpoints, `docs/openapi.yaml`, the B-043 design (amended by B-046) and a docs/08 rule.
  5. Run Pest on SQLite and PostgreSQL (including the concurrency race), Pint, PHPStan, Redocly and `composer audit`, plus migrate and rollback on PostgreSQL.
- [x] Delete hides a session from list and show; restore brings back the same id within 7 days and returns 410 after that.
- [x] An identical replay of a deleted session returns 200 and keeps it deleted; a changed payload is a 409.
- [x] Account deletion removes every session, including deleted ones; the migration rollback refuses while any session is deleted.
- [x] Pest (SQLite and PostgreSQL), Pint, PHPStan, Redocly lint and `composer audit` pass.
- **Out of scope:** frontend F-R3-14, guest import of Focus sessions (D4: deleted sessions will be excluded), production migration and deploy.

### B-050: Stabilize Google deletion clock-boundary tests

- **Status:** done ([PR #55](https://github.com/danishsary8/loomora_back/pull/55), merged at `67f4aef1b96f26fb0e5deac02ea16672543e3e0b`)
- **Owner:** Codex
- **Scope:** Keep B-042 regression checks deterministic when signing or verifying a test JWT crosses the second used to build the boundary fixture.
- **Plan:**
  1. Confirm the affected test uses a live clock for both the boundary input and verification.
  2. Freeze the test clock and leave the production verifier untouched.
  3. Run the Google deletion suite on SQLite and PostgreSQL, then open a test-only PR.

### B-049: Guest Focus history import

- **Status:** done ([PR #53](https://github.com/danishsary8/loomora_back/pull/53), merged at `6534a8ee61cb3f942a20599c5870dfc11482a313`)
- **Owner:** Codex
- **Scope:** D6 and D4. Add an optional Focus section to POST /import for finished, active guest sessions.
- **Plan:**
  1. Test import of linked sessions, lost-response replay and atomic rejection.
  2. Share the recording rules and payload consistency checks.
  3. Record imported sessions through B-043 after imported links exist.
  4. Update OpenAPI and verify both databases and static checks.

### B-047: Analytics and Plan vs Reality summary

- **Status:** done ([PR #51](https://github.com/danishsary8/loomora_back/pull/51), merged at `fa2b93d8d0028939daf599e4d9e93cbdb4a56812`)
- **Owner:** Codex
- **Scope:** Approved D7 and D12. Add the authenticated 92-day summary using existing Focus, to-do and schedule data.
- **Plan:**
  1. Exercise range limits, account isolation, aggregation and query count through the API.
  2. Validate the date range and aggregate Focus and to-do data alongside existing schedule occurrences.
  3. Document the OpenAPI response and add a shared fixture for frontend parity.
  4. Verify both databases, static checks and the contract, then open the PR for owner review.

### B-048: Notes and guest import

- **Status:** done ([PR #52](https://github.com/danishsary8/loomora_back/pull/52), merged at `6f1dfe4a935c90f225521801133c95ab5ebc169d`)
- **Owner:** Codex
- **Scope:** Notes (Must) in docs/02-feature-list.md; the owner's R3/R4 decision IDs are not recorded in this repository. Standalone and schedule-block notes with optional occurrence dates, plus idempotent guest import.
- **Plan:**
  1. Add API tests for ownership, note lifecycle and guest import.
  2. Add the notes table and authenticated, account-scoped endpoints.
  3. Extend the atomic import with the same note validation and reference rules.
  4. Publish OpenAPI and verify compatibility, rollback and both database suites.

### B-051: Historical streak summary

- **Status:** done ([PR #56](https://github.com/danishsary8/loomora_back/pull/56), merged at `fdb595ab28b16eb1a3450f024a761c54de166a3b`)
- **Owner:** Codex
- **Scope:** Extend the existing 92-day analytics summary with opening and daily reconstructed streak values and an explicit completeness indicator, following the planner decisions in PROGRESS.
- **Plan:**
  1. Test opening streaks, 400-day truncation, missing planned days, deleted records and local dates.
  2. Reuse the existing streak rules and bound reads while keeping GET /streak unchanged.
  3. Update the OpenAPI contract and progress, then verify both databases and required checks.
  4. Open a PR against v2-rebuild and verify its exact head and CI.

### B-052: Safe Notes import conflict diagnostics

- **Status:** done ([PR #57](https://github.com/danishsary8/loomora_back/pull/57), merged at `259cf901c309b6c3ecf43e94b40ccec5bf237bb9`)
- **Owner:** claude
- **Scope:** Owner-approved 2026-11-26 as phase 1 of revision-aware Notes reconciliation (phase 2 is B-053). Extend the `notes` section of the `POST /import` result additively with `skipped_details`: one `{id, status}` per skipped note, where status is `identical`, `different` or `deleted`, comparing the API-visible title, body, schedule block and occurrence date. No account content, timestamps or revisions are returned, no account note is ever changed, a note owned by another account stays a 422, and transactions and chunking are unchanged. A deleted account note keeps the guest copy (a later frontend flow may save it as a new note). QA-027 fail-closed behavior stays intact.
- **Plan:**
  1. Pest tests first: new, identical, different (guest and account edits, each visible field), deleted, mixed batch order, repeats, the 500 bound, cross-account ids and rollback.
  2. Build the diagnostic in `NoteService::import` from the row it already loads, with no extra query and no write.
  3. Document `NoteImportResult` in `openapi.yaml` with examples and backward compatibility, and docs/09.
  4. Run Pest on SQLite and PostgreSQL, Pint, PHPStan, Composer audit, Redocly and `git diff --check`.
- [x] Exactly one diagnostic per skipped note, in request order; no diagnostics for created notes.
- [x] Skipped account notes are never modified, and a failed request reports nothing and writes nothing.
- [x] Pest (SQLite and PostgreSQL), Pint, PHPStan, Composer audit and Redocly lint pass.
- **Out of scope:** B-053 revisions and conditional writes, PATCH or restore changes, frontend use of the diagnostics.

### B-054: Backend documentation reconciliation

- **Status:** done ([PR #58](https://github.com/danishsary8/loomora_back/pull/58), merged at `c2a09c2bcf991b549fb579c128ff2e68525fbbce`)
- **Owner:** claude
- **Scope:** Planner-approved 2026-11-26, docs only. Mark B-045, B-046, B-047, B-049, B-050, B-051 and B-052 `done` with their merged PRs. Replace B-048's unsupported "D8 and D9" reference. Bring `docs/08-data-model.md` in line with the implemented schema: missing `users` and `todos` columns, the `daily_intentions`, `focus_sessions` and `notes` tables, rule 9 (guest import, including the B-052 note diagnostics and client verification), and rules for Focus sessions, notes and account deletion. Record the approved B-053 planning decisions in PROGRESS as future behavior. No application code, migration, test, configuration, dependency or `openapi.yaml` change.
- **Plan:**
  1. Verify each PR is merged and each merge commit is on `origin/v2-rebuild`.
  2. Write every docs/08 statement from the migrations, models, requests and current API behavior.
  3. Add PROGRESS entries: the decision-reference correction, the B-053 planner decisions, and this task.
  4. Run `git diff --check` and Redocly, and review docs against migrations and task IDs against statuses.
- [x] Seven merged tasks marked `done` with PR and merge commit; no other status changed.
- [x] B-048 cites docs/02-feature-list.md instead of D8 and D9; historical PROGRESS lines unchanged.
- [x] docs/08 documents only implemented columns and behavior; `notes.revision` and other B-053 behavior are not described as existing.
- [x] Only `docs/TASKS.md`, `docs/PROGRESS.md` and `docs/08-data-model.md` changed.
- **Out of scope:** B-053 implementation, `openapi.yaml`, recording the unsupplied R3/R4 owner specification.

### B-053: Revision-aware Notes and conditional writes

- **Status:** done
- **Merge:** [PR #59](https://github.com/danishsary8/loomora_back/pull/59) at `2c95ed6`.
- **Owner:** claude
- **Scope:** Planner-approved 2026-11-26 (decisions in PROGRESS, "B-053 planner decisions"). Add a server-controlled `notes.revision` (integer, at least 1, default 1). It goes up by one on a real edit, a delete, a restore and a schedule block delete that detaches the note. `PATCH /notes/{note}` takes an optional `expected_revision` body field and `DELETE /notes/{note}` an optional `expected_revision` query parameter: a stale value is a 409 with no write, an invalid one a 422, and an omitted one keeps the old behavior. An identical `POST /notes` replay of an active note returns 200; a changed one, a deleted note or another account's UUID is 409. `revision` is in every Note response. Import and the B-052 diagnostics are unchanged. No automatic overwrite or restore, no bulk conflict action, no frontend change.
- **Plan:**
  1. Pest tests first, plus a PostgreSQL race test through a shared `Tests\Support\RowLockRace` helper (also used by the Focus race test).
  2. Migration with a PostgreSQL CHECK, model default and cast, revision in `NoteResource`.
  3. `NoteService` owns create, conditional update and delete, restore and block detach, all under the existing row lock.
  4. Update `openapi.yaml`, docs/08 and PROGRESS, then verify both databases and the static checks.
- [x] Revision starts at 1 (create, import, backfill) and the client cannot set it.
- [x] Conditional PATCH and DELETE: matching applies, stale is 409 with no write, invalid is 422, omitted is legacy; no-op PATCH keeps the revision.
- [x] Restore and schedule block delete bump the revision (deleted notes included) in one transaction.
- [x] Identical POST replay is 200, changed or deleted is 409, another account stays 409.
- [x] Two PATCHes with the same revision on PostgreSQL give one 200 and one 409.
- [x] B-052 import diagnostics unchanged.
- **Out of scope:** frontend use (F-R4-04), a required `expected_revision`, revisions in import diagnostics, deploy.

### B-055: Patch runtime dependency advisories and gate them in CI

- **Status:** review
- **Owner:** claude
- **Scope:** `composer audit --locked` reported 9 advisories in `symfony/http-client` (4), `symfony/routing` (2), `symfony/mailer` (2) and `symfony/http-kernel` (1). Update the Symfony packages to the patched 7.4.24 line (no major upgrade needed) and fail CI on any future advisory.
- **Done when:**
  - [x] `composer audit --locked` reports no advisories.
  - [x] `composer validate --strict` passes.
  - [x] CI runs `composer audit --locked` after install.
  - [x] CI tests, Pint, PHPStan and Docker jobs pass on the PR (run 45813615986).
- **Files:** `composer.lock`, `.github/workflows/ci.yml`, `docs/TASKS.md`, `docs/PROGRESS.md`
