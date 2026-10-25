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

- **Q8 (2026-10-25, B-008):** Real Brevo sending is untested (needs `BREVO_API_KEY` and a verified sender in Brevo). Danish: when ready, set `MAIL_MAILER=brevo`, `BREVO_API_KEY` and `MAIL_FROM_ADDRESS` (a sender verified in Brevo) on Render, then register a test account and confirm the email arrives. Also: `MAIL_MAILER` is a new env name, so `render.yaml` (in PR #7, B-004) needs it added; I'll add it there unless you prefer otherwise.
- **Q9 (2026-10-25, B-008):** Frontend routes assumed by the email links: `{FRONTEND_URL}/verify-email?id=&expires=&signature=` (POST those three to `/auth/email/verify`) and `{FRONTEND_URL}/reset-password?token=&email=` (POST with `password` to `/auth/password/reset`). OK for the front repo? Verify links last 24 h, reset links 60 min.

- **Q6 (2026-10-24, B-006):** docs/08 rule 1 shows undated to-dos only "if not done", so checking off an undated item makes it vanish from Today right away (a due-today item stays visible as done). Implemented exactly as written. Should an undated item done *today* stay visible until the day ends?
- **Q7 (2026-10-24, B-006):** Choices to confirm: (a) `category` is optional on create and defaults to `other` (docs/02 says optional, docs/08 has no null); (b) `?date=` must be within 1 day of the user's local today, otherwise 422; (c) another user's to-do returns **403** (policy) rather than 404; (d) to-dos sort overdue first, then by due date, then creation time.
- **Q10 (2026-10-25, B-010):** docs/08 says `schedule_items.days` is `smallint[]`. I used `jsonb` (an array of 0 to 6) because Laravel has no portable array column and local tests run on sqlite. Same data and API shape; B-011 can still filter by weekday in PHP or with `jsonb` operators. OK, or switch to a real Postgres array (tests would then need Postgres everywhere)?
- **Q11 (2026-10-25, B-010):** Choices to confirm: `category` optional (defaults to `other`, same as to-dos); `starts_on` optional (defaults to today in the user's time zone); `ends_on` must be on or after `starts_on`; `end_time` must be later on the same day (no items crossing midnight); another user's item -> 403.

- ~~**Q2**~~ Worked around 2026-10-25: the image is now built and smoke-tested by a `docker` job in CI (GitHub runners can reach `deb.debian.org`). Allowing `deb.debian.org` here is still useful for local builds. Original question: (2026-10-24, B-004) `docker build .` cannot finish in this cloud session: the network policy returns 403 for `deb.debian.org`, so `apt-get install libpq-dev` (needed for `pdo_pgsql`) fails. Danish: please add `deb.debian.org` under Allowed domains in this environment's network settings (https://code.claude.com/docs/en/cloud-environments#network-access), or say if a `docker build` job in CI is preferred as the check instead. Work is saved on `feat/B-004-docker-render`; no PR until the build is proven.

- **Q14 (2026-10-25, B-015):** Choices to confirm: (a) `GET /auth/google/redirect` also accepts an optional `timezone` (not in docs/09), because a new account needs one; without it the account starts on `UTC`; (b) Google sign-in and sign-up both link `google_id` to an existing account with the same email and mark the email verified, only when Google says the email is verified; (c) frontend route assumed: `{FRONTEND_URL}/auth/google/callback?code=` or `?error=` (`account_not_found`, `account_already_exists`, `email_not_verified`, `google_failed`); (d) stateless OAuth: the intent rides in an encrypted state that expires after 10 minutes (no session/cookie, since API and frontend are on different domains). Real login needs Render secrets (separate step, per the task).

- **Q12 (2026-10-25, B-011 to B-013 on hold):** B-011 (occurrences) is built directly on how `schedule_items.days` is stored (Q10), so I did not start it until Q10 is answered. B-012 (streak) needs B-006 and B-011; B-013 (import) needs to-dos, schedule items, exceptions and check-ins. I took B-014 and B-015 first since they only need B-005. Answer Q10 and the next session starts B-011.
- **Q13 (2026-10-25, B-014):** Choices to confirm: `POST /feedback` works for guests too (docs/08 allows `user_id` null; a token links it to the account), limited to 10 per hour, message max 5000 chars; a user's feedback is deleted with the account (docs/08 rule 10 "all their rows"); Google-only accounts cannot delete yet (422) until B-015 adds Google re-auth; the feedback email is plain text with Reply-To set to the user.

- **Q15 (2026-10-25, B-016):** Choices to confirm: (a) `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'`, `Referrer-Policy: no-referrer`, `nosniff` on every response, HSTS only over HTTPS; (b) `trustProxies(at: '*')` because Render's proxy IPs are not fixed (safe only while the app is reachable only through Render); (c) `APP_DEBUG` is not listed in `render.yaml` because Laravel defaults it to false when unset; the `.env.example` comment says it must be false in production.

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

## Planner answers (2026-10-25, review 2)
- B-001, B-002, B-003, B-005 are merged and marked done. Good work.
- **Q3:** Verification email and password reset are task B-008. **Q4:** `docs/openapi.yaml` is task B-009. **Q5:** Confirmed: wrong password -> 422 with `errors.email`; min password 8; keep the response shapes and document them in B-009.
- B-004 (Docker/Render) is still open: finish it, but do not deploy.

### 2026-10-25: B-008 Email verification and password reset (Brevo)
- **Branch / PR:** `feat/B-008-email` (PR into `v2-rebuild`)
- **What changed:** Endpoints: `POST /auth/email/verify` (public, throttle `auth`), `POST /auth/email/resend` (signed in, throttle `verification` 3 per 10 min per user, no mail if already verified), `POST /auth/password/forgot` (same answer for unknown emails), `POST /auth/password/reset` (Laravel password broker; revokes all tokens). Register now sends the verification mail (a mail failure is reported, signup still succeeds). `EmailVerification` service signs `id|email|expires` with HMAC (APP_KEY), so links expire after 24 h and die when the email changes. `VerifyEmailMail`, `ResetPasswordMail`, neo-brutalist HTML layout on cream `#F5EDE6`, all text in `lang/en/mail.php` and `lang/en/auth_flow.php`. Mail transport by env: `MAIL_MAILER=log` (local/test, added to `.env.example`) or `brevo` via `symfony/brevo-mailer` (`Mail::extend`, key from `BREVO_API_KEY`). `MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` fall back to defaults when empty. `FRONTEND_URL` is now `config('app.frontend_url')`.
- **How it was verified:** `php artisan test` -> 31 passed (130 assertions) on sqlite and Postgres 16. `./vendor/bin/pint --test` -> passed. `gitleaks dir .` -> no leaks. Tests (with `Mail::fake()`): mail on register, register survives a mail failure, verify with the mailed link, tampered/expired/stale(email changed)/missing link rejected, resend, no resend when verified, resend throttle, resend needs auth, forgot sends a frontend link, unknown email same answer and no mail, reset works + tokens revoked + login with new password, invalid/reused/expired token rejected, password validation, both mails render with link and translated text, Brevo transport builds from config.
- **Problems:** `Mail::fake()` also swaps the container's mail manager, so the transport test lives in its own file.
- **Questions:** Q8 (real Brevo test, `MAIL_MAILER` in render.yaml), Q9 (frontend link paths).

### 2026-10-24: B-006 To-dos API
- **Branch / PR:** `feat/B-006-todos`, stacked on `feat/B-005-auth` (needs its users/auth), PR into `v2-rebuild`.
- **What changed:** `todos` migration (UUID id, `user_id` FK with cascade delete, `title` max 200, `category` enum of the 6 categories, nullable `due_date`/`done_at`, index on user+due_date). `Todo` model with `forToday` scope and computed `isOverdueOn`/`isOldOn` (overdue = due before today and not done; old = overdue more than 30 days). `TodoPolicy` (owner only), `TodayRequest` (date checked against the user's time zone), `StoreTodoRequest` (500 per user limit), `UpdateTodoRequest`, `TodoResource` (`overdue`, `old` flags). `TodoController` + routes: `GET /todos?date=`, `POST /todos`, `PATCH /todos/{id}`, `DELETE /todos/{id}`, `POST`/`DELETE /todos/{id}/done` (`{todo}` must be a UUID). Published `lang/en` and added the two new messages there.
- **How it was verified:** `php artisan test` -> 35 passed (153 assertions) on sqlite and on Postgres 16 (Docker). `./vendor/bin/pint --test` -> passed. Mutation check: making `TodoPolicy` always allow made the ownership test fail, so the test really guards access. Tests cover due today (done and not), undated not done, undated done hidden, overdue first + flag, old after 30 days, future hidden, date validation, create defaults, validation, `user_id` ignored, 500 limit, edit, delete, 404 for unknown/malformed id, done/undone, idempotent done, other users' to-dos not listed and 403 on edit/done/undone/delete, 401 without token, cascade on account delete.
- **Problems:** the Docker daemon had stopped while the session was idle; restarted it for the Postgres run.
- **Questions:** Q6, Q7.

### 2026-10-24: B-004 Dockerfile and Render blueprint (first attempt, blocked)
- **Branch / PR:** `feat/B-004-docker-render` (pushed, no PR yet)
- **What changed:** `Dockerfile` (composer stage + `php:8.3-apache`, `pdo_pgsql`, `opcache`, docroot `public/`). `docker/entrypoint.sh`: binds Render's `$PORT`, caches config/routes, runs `php artisan migrate --force` with 3 attempts (Neon wakes slowly), then exits 1 with a FATAL line if all fail. `render.yaml`: `loomora-api`, free plan, Docker, health check `/api/v1/health`, 11 env names from docs/10 with `sync: false` (no values). `.dockerignore` excludes `.env`, `vendor`, `legacy`, `docs`, `tests`.
- **How it was verified:** `render.yaml` checked against docs/10: all 11 names, no values. Composer stage of the build succeeds. The apt stage cannot run here (see Q2), so `docker build .` is NOT verified.
- **Problems:** Docker Hub returned 429 (rate limit); images were pulled from `mirror.gcr.io` and tagged locally. A local-only Dockerfile copy with the sandbox proxy CA was used for testing; it is not committed.
- **Questions:** Q2.

### 2026-10-25: B-004 Dockerfile and Render blueprint (finished)
- **Branch / PR:** `feat/B-004-docker-render` (PR into `v2-rebuild`)
- **What changed:** Added a `docker` job to `.github/workflows/ci.yml`: builds the image, starts it against a Postgres 16 service and waits for `GET /api/v1/health`, then starts it with a wrong `DB_URL` and checks it exits non-zero with the `FATAL: migrations failed` line. Dockerfile, entrypoint, `render.yaml`, `.dockerignore` unchanged from the first attempt. Nothing deployed.
- **How it was verified:** Locally: `config:cache` and `route:cache` succeed; ran the entrypoint script (Apache paths pointed at temp files) against Postgres 16 -> config and routes cached, all 5 migrations DONE, then the server command ran; with a wrong `DB_URL` -> 3 attempts, `FATAL: migrations failed after 3 attempts, refusing to start`, server command never ran; `$PORT` rewrite gives `Listen 10000` / `<VirtualHost *:10000>`. `docker build .` itself runs in CI (this session's network blocks the Debian mirror), so the PR's `docker` job is the "Done when" check.
- **Problems:** none new.
- **Questions:** none.

### 2026-10-25: B-007 Remove unused Laravel frontend defaults
- **Branch / PR:** `feat/B-007-api-only` (PR into `v2-rebuild`)
- **What changed:** Deleted `package.json`, `vite.config.js`, `resources/js/*`, `resources/css/app.css`, `resources/views/welcome.blade.php` and the `/` web route (`routes/web.php` now only has a comment). Kept `resources/views/` (with `.gitkeep`) for the mail templates coming in B-008; Laravel's own mail views live in `vendor/`. Removed the `npm install` / `npm run build` steps from the Composer `setup` script, and `composer dev` now just runs `php artisan serve` (it used `npx concurrently` and Vite).
- **How it was verified:** `composer validate` -> valid. `php artisan test` -> 15 passed. `./vendor/bin/pint --test` -> passed. `php artisan serve` + `curl /api/v1/health` -> `{"status":"ok"}`; `curl /` -> 404.
- **Problems:** first serve check gave `GET /` 500 because my `routes/web.php` edit had not been applied (a chained shell command stopped early); fixed and re-checked.
- **Questions:** none.

### 2026-10-25: B-009 OpenAPI contract file
- **Branch / PR:** `feat/B-009-openapi` (PR into `v2-rebuild`). Docs only; it also documents the endpoints from PR #5 (to-dos, B-006) and PR #8 (email/password, B-008), which are still in review.
- **What changed:** `docs/openapi.yaml` (OpenAPI 3.1): `/health`, `/auth/register`, `/auth/login`, `/auth/logout`, `/auth/email/verify`, `/auth/email/resend`, `/auth/password/forgot`, `/auth/password/reset`, `/me`, `/todos` (GET, POST), `/todos/{todo}` (PATCH, DELETE), `/todos/{todo}/done` (POST, DELETE). Bearer auth, shared error responses (401, 403, 404, 422, 429 with `Retry-After`), request/response examples, rate limits and the documented shapes (`{token, expires_at, user}` vs `{data: user}`). CLAUDE.md: new "API contract" section (update openapi.yaml in the same PR, lint command, response shapes).
- **How it was verified:** `npx @redocly/cli@1.34.5 lint docs/openapi.yaml` -> "Your API description is valid", 2 warnings left on purpose: server URL is localhost (no production URL yet) and `/health` has no 4xx response (it has none).
- **Problems:** a `: ` inside a description broke YAML parsing; quoted it.
- **Questions:** none. If PR #5 or #8 changes during review, this file needs the same change.

### 2026-10-25: B-010 Schedule items API
- **Branch / PR:** `feat/B-010-schedule-items`, stacked on `feat/B-009-openapi` (PR #9) so the contract is updated in the same PR (CLAUDE.md rule). PR into `v2-rebuild`.
- **What changed:** `schedule_items` migration (UUID id, `user_id` FK cascade, `title` max 200, `category` enum, `start_time`/`end_time` time, `days` jsonb, `starts_on`, nullable `ends_on`). `ScheduleItem` model + factory, `User::scheduleItems()`. `ScheduleItemPolicy` (owner only). `StoreScheduleItemRequest` / `UpdateScheduleItemRequest` sharing rules: HH:MM times, start < end, days non-empty, distinct, 0 to 6, `ends_on` >= `starts_on`, 100 per user; edits are checked against stored values. `ScheduleItemResource` (times as HH:MM, days sorted). Routes: `GET`/`POST /schedule/items`, `PATCH`/`DELETE /schedule/items/{item}` (`{item}` must be a UUID). Messages in `lang/en/schedule.php`. `docs/openapi.yaml` updated (paths, `Time`, `Weekdays`, `ScheduleItem` schemas).
- **How it was verified:** `php artisan test` -> 30 passed (144 assertions) on sqlite and Postgres 16. `./vendor/bin/pint --test` -> passed. Redocly lint -> valid (same 2 expected warnings). Mutation check: policy always-allow makes the ownership test fail. Tests: create for several days, defaults (starts_on in user time zone across the UTC date line, category), explicit dates, start >= end rejected, validation of days/times/title/category/dates, `user_id` ignored, 100 limit, list only mine in time order, edit all repeats (checked against stored times), delete, 404 unknown/malformed id, 403 on another user's item, 401 without token, cascade on account delete.
- **Problems:** none.
- **Questions:** Q10, Q11.

### 2026-10-24: B-011 Schedule occurrences, exceptions, check-ins, now/next
- **Branch / PR:** `feat/B-011-schedule-occurrences` (PR into `v2-rebuild`). **Stacked on `feat/B-010-schedule-items` (PR #10, unmerged)**; merge B-010 first, then this diff shrinks to B-011 only.
- **What changed:** migrations + models for `schedule_exceptions` and `schedule_checkins` (UUID, cascade on item delete, unique (item, date)). `ScheduleService`: `forDate`, `forRange`, `nowAndNext`, `matchesRule` (docs/08 rules 4, 6, 7; weekday 0 = Monday). Endpoints: `GET /schedule/day?date=`, `/schedule/week?start=`, `/schedule/now?date=&time=`, `PUT|DELETE /schedule/items/{id}/exceptions/{date}`, `POST|DELETE /schedule/items/{id}/checkins/{date}`, `POST /schedule/copy-day`. Occurrence shape: `{item_id, date, title, category, start_time, end_time, done, overridden}`. Day returns `{date, data: [...]}`, week returns `{start, data: [{date, occurrences}]}` (7 days), now returns `{now, next}`, each an occurrence or null.
- **How it was verified:** `pint --test` passed; `php artisan test` -> 58 passed (280 assertions) on sqlite and the same 58 on local Postgres 16. 28 new tests cover weekday match, starts_on/ends_on inclusive, ordering, skip, override (full and title-only), restore, replacing an exception, check-in toggle and idempotency, now/next (start inclusive, end exclusive, Free, nothing more today, skipped ignored, overrides used), time zone checks incl. near-midnight UTC, copy-day, cross-user 403, cascade on item delete.
- **Problems:** none. `docs/openapi.yaml` updated with all B-011 endpoints; `npx @redocly/cli lint docs/openapi.yaml` is valid. Also: B-004..B-010, B-014, B-015 already have open PRs but `v2-rebuild`'s TASKS.md still lists them `todo`, so a scheduled run starting from `v2-rebuild` would redo them. Please merge or mark them.
- **Questions:**
  - **Q12:** "Free" and "Nothing more today" are returned as `null` (`now`/`next`); the client supplies the text via i18n. OK?
  - **Q13:** `copy-day` is read as: every item that runs on `from_weekday` also runs on `to_weekdays` (edits the repeat definitions, no new rows). Is that the intended meaning?
  - **Q14:** Exceptions and check-ins are only allowed on dates where the item actually occurs (else 422). Check-ins on future dates are allowed. Should future check-ins be refused?
  - **Q15:** `/schedule/now` rejects a `date` more than 1 day from the user's today (docs/09 "checked against the user's time zone"). `/day` and `/week` accept any date.

### 2026-10-24: B-012 Streak API
- **Branch / PR:** `feat/B-012-streak` (PR into `v2-rebuild`). **Stacked on B-011 (`feat/B-011-schedule-occurrences`, PR #13, which sits on B-010) and merges B-006 (`feat/B-006-todos`, PR #5)**, because it needs to-dos and the schedule. Merge #5 and #10 to #13 first; this diff then shrinks to B-012. Merge conflicts in `routes/api.php`, `User.php`, `PROGRESS.md` were resolved by keeping both sides.
- **What changed:** `StreakService` (rule 8): walks back from yesterday, a counted day adds 1, a planned day with no check-off breaks, an empty day is skipped, today is never judged and adds 1 once counted. Counted = a to-do `done_at` on that local day (user time zone) or a schedule check-in with that `date`. Planned = a to-do due that day or a (non-skipped) schedule occurrence. Stores `current_streak`, `longest_streak` (never lowered), `last_streak_date`. `GET /streak?date=` returns `{data: {current_streak, longest_streak, today_counted}}`. Recomputed on to-do done/undone and on schedule check-in/undo (using the server's local "today" for the user). `docs/openapi.yaml` has `/streak`.
- **How it was verified:** `pint --test` passed; `php artisan test` -> 101 passed (421 assertions) on sqlite and on local Postgres 16; openapi lint valid. 23 new tests: zero, today counted, consecutive days, today not judged, undated to-do, schedule check-in, planned-day break, skipped empty days, schedule planned day, skip exception, other users ignored, time zones (UTC+7 midnight edge both ways, UTC-7), stored values, longest kept, endpoint, hooks on to-do and schedule check-off/undo, `/me`.
- **Problems:** none.
- **Questions:**
  - **Q16:** A schedule check-in counts on the occurrence `date`, not on the day it was tapped (a late check-in for yesterday counts for yesterday). To-dos count on the `done_at` local day. OK?
  - **Q17:** Overdue to-dos do not make older days "planned"; only a to-do whose `due_date` is that day does. Undated to-dos never plan a day. OK?
  - **Q18:** The look-back is capped at 400 days, so a current streak is at most 401; `longest_streak` is kept separately and never lowered.

### 2026-10-24: B-013 Guest data import
- **Branch / PR:** `feat/B-013-import` (PR into `v2-rebuild`). **Stacked on B-012 (`feat/B-012-streak`, PR #14)**, which sits on B-011 (#13), B-010 (#10) and includes B-006 (#5). Merge those first.
- **What changed:** `POST /import` with `ImportRequest` (all validation and ownership/limit checks before any write), `ImportService` (one `DB::transaction`), `ImportController`, `lang/en/import.php`. Payload: `todos`, `schedule_items`, `exceptions`, `checkins`, every row with its client UUID (`exceptions`/`checkins` point at `schedule_item_id`). Response `{data: {todos|schedule_items|exceptions|checkins: {created, skipped}}}`. Existing rows (same id, or same item+day for exceptions/check-ins) are left untouched and counted as skipped, so a repeat creates nothing and never overwrites newer server edits. Streak is recomputed once after the import. `docs/openapi.yaml` has `/import`.
- **How it was verified:** `pint --test` passed; `php artisan test` -> 133 passed (572 assertions) on sqlite and on local Postgres 16; openapi lint valid. 32 new tests: success with ids kept, visible via normal endpoints, partial/empty payload, starts_on default, streak recompute, repeat import (no duplicates, no overwrite, same-day exception/check-in skipped), invalid rows write nothing (every row kind), duplicate ids/days, forced mid-write failure rolls everything back, limits (500 to-dos / 100 items counting existing, payload cap), other users' ids refused for all four kinds, items of other users, `user_id` from client ignored.
- **Problems:** none.
- **Questions:**
  - **Q19:** On a repeat import an existing row is skipped, not updated ("server copy wins"). OK, or should the import update rows the user already has?
  - **Q20:** Imported exceptions and check-ins are not required to fall on a date the item occurs (guest data may predate later edits to `days`); they only need a valid date and an item. Should they be?
  - **Q21:** The whole import is refused (422) if it would pass 500 to-dos or 100 items, rather than importing part. Payload caps: 5000 exceptions, 10000 check-ins.

### 2026-10-25: B-015 Google sign-in (code only)
- **Branch / PR:** `feat/B-015-google`, stacked on `feat/B-009-openapi` (PR #9) for the contract. PR into `v2-rebuild`.
- **What changed:** `laravel/socialite` added; `config/services.php` `google` block (env names only). `GoogleAuthController`: `GET /auth/google/redirect?intent=sign_in|sign_up` (stateless, encrypted expiring state, scopes openid/email/profile), `GET /auth/google/callback` (errors `account_not_found` / `account_already_exists` per intent, plus `email_not_verified` and `google_failed`; links `google_id`, marks email verified, creates Google-only users with no password), redirect to the frontend with a 64-char one-time code stored hashed in the cache for 5 minutes; `POST /auth/google/exchange` (code -> `{token, expires_at, user}`, single use via `Cache::pull`). No logging of tokens or codes. Routes under the `auth` throttle. `config('app.frontend_url')` added (also added in PR #8; trivial conflict for whichever merges second). Messages in `lang/en/google.php`. `docs/openapi.yaml` updated.
- **How it was verified:** `php artisan test` -> 28 passed (149 assertions) on sqlite and Postgres 16, with a mocked Socialite provider. `./vendor/bin/pint --test` -> passed. Redocly lint -> valid (2 new warnings: the two Google redirect endpoints have no 2xx, they only redirect). `grep` shows no logging calls in the controller. Tests: redirect builds a Google URL with client id and encrypted intent/time zone; intent validation; sign-up creates a Google-only verified user; sign-up when account exists; sign-in when none exists; sign-in links an existing email account (case-insensitive) and verifies it; lookup by google_id when email changed; unverified Google email refused; bad/expired/forged state and Google `error` fail safely; Google token failure fails safely; exchange works once; expired/unknown/short code rejected; Google-only user cannot use password login.
- **Problems:** none.
- **Questions:** Q14.

### 2026-10-25: B-014 Feedback and account endpoints
- **Branch / PR:** `feat/B-014-feedback-account`, stacked on `feat/B-009-openapi` (PR #9) for the contract. PR into `v2-rebuild`.
- **What changed:** `feedback` table (UUID, nullable `user_id` FK cascade, message, created_at) + `Feedback` model. `POST /feedback` (guests or signed in, throttle `feedback` 10/hour): stores the row, then emails `FEEDBACK_TO_EMAIL` (`config('services.feedback.to')`) as plain text with Reply-To; a mail failure is reported but the row stays. `PATCH /me` (name, timezone only). `POST /me/survey` (goal enum + heard_from, both optional; always sets `survey_done`). `DELETE /me` (password confirmation) -> `User::deleteWithData()` in one transaction: tokens, password reset rows, sessions, then the user (FK cascades remove feedback, and to-dos/schedule once those PRs land). Messages in `lang/en/account.php`. `docs/openapi.yaml` updated.
- **How it was verified:** `php artisan test` -> 29 passed (147 assertions) on sqlite and Postgres 16. `./vendor/bin/pint --test` -> passed. Redocly lint -> valid. The "no rows behind" test walks every table in the schema and checks no `user_id`, `tokenable_id` or `email` still points at the deleted user, while another user's token and feedback survive.
- **Problems:** the plain-text feedback email HTML-escaped `<`, `&` and quotes; switched the text template to raw output and added a test with `&` and quotes.
- **Questions:** Q12 (B-011 to B-013 hold), Q13.

## Planner review (2026-10-25)
- B-004 to B-014 reviewed and merged (B-015 pending a conflict). Code is clean: no debug leftovers, no client-supplied `user_id`, tests green in CI. Good work.
- Gap found: CORS is not configured, so a browser on Vercel cannot call the API. Added B-016. Also added B-017 (static analysis) and B-018 (README and DEPLOY.md).

### 2026-10-25: B-017 Static analysis and unused-code check
- **Branch / PR:** `feat/B-017-larastan` (PR into `v2-rebuild`)
- **What changed:** `larastan/larastan` ^3 (PHPStan 2) dev dependency; `phpstan.neon` at level 6 over `app`, `bootstrap/app.php`, `config`, `database`, `routes`. CI runs PHPStan after Pint. Fixes for every finding: typed `@property` docblocks on all models (date casts now Carbon), generic `HasFactory`, `@return array<int, Closure>` on FormRequest `after()` hooks, generic `ImportService::fill()` and `setAttribute()` for id/created_at, `User` narrowing before `associate()`, a typed `GoogleProvider` helper for `stateless()`, typed import payload before `collect()`, removed unnecessary nullsafe calls. Unused code: removed `lang/en/pagination.php` and `lang/en/passwords.php` (no references; framework defaults remain); no unused custom translation keys; policies look unreferenced to grep but are auto-discovered.
- **How it was verified:** CI: Pint, PHPStan (0 errors) and tests (175) green, Docker job green. Locally: Pint passed, 175 tests on sqlite and Postgres 16. First CI run found 487 errors (about 440 from Pest closures, 47 in app code); second run 12; third run 0.
- **Problems:** this session cannot download new packages from GitHub anymore ("Could not authenticate against github.com"), so Larastan was added with `composer require --dev --no-install` and PHPStan only ran in CI. Pest tests are excluded from analysis because `$this` inside a Pest closure is the test case, which PHPStan cannot see; they still run as tests.
- **Questions:** none.

### 2026-10-25: B-016 Production hardening (CORS, headers, config)
- **Branch / PR:** `feat/B-016-hardening` (PR into `v2-rebuild`)
- **What changed:** `config/cors.php` published: only `api/*`, origins read from `FRONTEND_URL` (comma-separated, trailing slashes trimmed), methods GET/POST/PUT/PATCH/DELETE/OPTIONS, headers Accept/Authorization/Content-Type/X-Requested-With, exposes `Retry-After`, no credentials (bearer tokens), 24 h preflight cache. `SecurityHeaders` middleware (nosniff, frame DENY, no-referrer, CSP `default-src 'none'`, HSTS over HTTPS) appended globally. `trustProxies(at: '*')` so Render's `X-Forwarded-Proto` gives correct `https` URLs. `HealthController`: `GET /api/v1/health` unchanged and DB-free; `?deep=1` runs `select 1` and returns 503 `{status: error, database: unavailable}` on failure. API errors were already JSON (B-005); test added that a 404 with debug off has no trace. `.env.example` comments for `APP_DEBUG` and `FRONTEND_URL`. `docs/openapi.yaml` health updated.
- **How it was verified:** `php artisan test` -> 172 passed (736 assertions) on sqlite and Postgres 16. `./vendor/bin/pint --test` -> passed. Redocly lint -> valid. Tests: allowed origin passes a PATCH preflight (no credentials header); second listed origin allowed; other origin gets no `Access-Control-Allow-Origin` on preflight or GET; comma-separated `FRONTEND_URL` parsing; security headers; HSTS only when `X-Forwarded-Proto: https`; 404 JSON without trace when debug is off; plain health never queries the DB; deep health ok; deep health 503 when the DB fails.
- **Problems:** `env()` ignores `putenv()`, so the parsing test sets `$_SERVER`/`$_ENV` and restores them.
- **Questions:** Q15.
