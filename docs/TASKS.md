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
- **Status:** review
- **Steps:**
  1. Fix `.gitignore` so `legacy/vendor`, `legacy/node_modules`, `legacy/.env`, `legacy/storage/*.key` are ignored.
  2. Add `legacy/README.md`: "Old Daycraft code. Reference only. Do not run or import. Deleted at the end of R1."
  3. Do not copy the legacy mail-diagnostic or mail-test endpoints anywhere.
- **Done when:**
  - [x] `git status` is clean after the change and no secrets are tracked
  - [x] `legacy/README.md` exists
- **Files:** `.gitignore`, `legacy/README.md`

### B-002: Scaffold Laravel 12 app
- **Status:** review
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
- **Status:** review
- **Steps:**
  1. Add `.github/workflows/ci.yml`: PHP 8.3, composer install (cached), Postgres 16 service, run Pint check and `php artisan test`.
  2. Run on pull requests and pushes to `main` and `v2-rebuild`.
- **Done when:**
  - [ ] The workflow runs green on the PR
- **Files:** `.github/workflows/ci.yml`

### B-004: Dockerfile and Render blueprint
- **Status:** todo
- **Steps:**
  1. Add a production `Dockerfile` (PHP 8.3 + Apache or php-fpm + nginx) that runs migrations on start with `php artisan migrate --force` and fails loudly if they fail.
  2. Add `render.yaml` for a service named `loomora-api` (free plan, Docker, health check `/api/v1/health`), listing env variable NAMES only.
  3. Do not deploy. Do not add secrets.
- **Done when:**
  - [ ] `docker build .` succeeds
  - [ ] `render.yaml` lists all variables from docs/10 with no values
- **Files:** `Dockerfile`, `render.yaml`, `.dockerignore`

### B-005: Users table and email/password auth
- **Status:** todo
- **Steps:**
  1. Migration and model for `users` exactly as in docs/08-data-model.md (UUID id).
  2. Endpoints: register, login, logout, me (docs/09). Sanctum bearer tokens, 30-day expiry.
  3. Form requests for validation, API resources for output, rate limits on auth routes.
  4. Pest tests: register, login, wrong password, me, logout.
- **Done when:**
  - [ ] `php artisan test` passes with the new tests
  - [ ] No endpoint accepts `user_id` from the client
- **Files:** `database/migrations/*users*`, `app/Models/User.php`, `app/Http/Controllers/Api/V1/AuthController.php`, tests

### B-006: To-dos API
- **Status:** todo
- **Steps:**
  1. Migration, model, policy and controller for `todos` per docs/08 and docs/09.
  2. `GET /todos?date=` implements the Today list rules and the computed `overdue` flag.
  3. Done and undone endpoints.
  4. Pest tests for every rule: due today, undated, overdue, future hidden, other users' data not visible.
- **Done when:**
  - [ ] All tests pass
  - [ ] A user cannot read or change another user's to-do (test proves it)
- **Files:** migration, `app/Models/Todo.php`, `TodoController.php`, tests

### B-007: Remove unused Laravel frontend defaults
- **Status:** todo
- **Steps:**
  1. Remove `package.json`, `vite.config.js`, `resources/js`, `resources/css` and the `welcome` view and web route. Keep mail views and API-only routing.
  2. Make sure `php artisan test` and Pint still pass.
- **Done when:**
  - [ ] Tests and Pint pass; `GET /api/v1/health` still returns ok
- **Files:** `package.json`, `vite.config.js`, `resources/*`, `routes/web.php`

## Done

_None yet._
