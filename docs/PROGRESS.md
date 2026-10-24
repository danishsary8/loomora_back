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

- **Q1 (2026-10-24, B-003 to B-006):** These tasks need the Laravel app from B-002, which is still in review (PR #2). Branching them from `v2-rebuild` now gives no app to build or test. Should I wait for B-002 to merge (current choice), or stack the next branches on `feat/B-002-scaffold-laravel`?

- **Q2 (2026-10-24, B-004):** `docker build .` cannot finish in this cloud session: the network policy returns 403 for `deb.debian.org`, so `apt-get install libpq-dev` (needed for `pdo_pgsql`) fails. Danish: please add `deb.debian.org` under Allowed domains in this environment's network settings (https://code.claude.com/docs/en/cloud-environments#network-access), or say if a `docker build` job in CI is preferred as the check instead. Work is saved on `feat/B-004-docker-render`; no PR until the build is proven.

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

### 2026-10-24: B-004 Dockerfile and Render blueprint (blocked)
- **Branch / PR:** `feat/B-004-docker-render` (pushed, no PR yet)
- **What changed:** `Dockerfile` (composer stage + `php:8.3-apache`, `pdo_pgsql`, `opcache`, docroot `public/`). `docker/entrypoint.sh`: binds Render's `$PORT`, caches config/routes, runs `php artisan migrate --force` with 3 attempts (Neon wakes slowly), then exits 1 with a FATAL line if all fail. `render.yaml`: `loomora-api`, free plan, Docker, health check `/api/v1/health`, 11 env names from docs/10 with `sync: false` (no values). `.dockerignore` excludes `.env`, `vendor`, `legacy`, `docs`, `tests`.
- **How it was verified:** `render.yaml` checked against docs/10: all 11 names, no values. Composer stage of the build succeeds. The apt stage cannot run here (see Q2), so `docker build .` is NOT verified.
- **Problems:** Docker Hub returned 429 (rate limit); images were pulled from `mirror.gcr.io` and tagged locally. A local-only Dockerfile copy with the sandbox proxy CA was used for testing; it is not committed.
- **Questions:** Q2.
