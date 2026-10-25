# Loomora

> Plan your day, focus on it, and see how it really went.

Loomora is a mobile-first to-do list with a fixed weekly schedule, built as an installable web app (PWA). This repository is the **backend API**. The web app lives in `Loomora_front`.

**Status:** Release 1 backend in progress. The previous Daycraft backend is kept in [`legacy/`](legacy/) for reference only.

## Stack

| Part | Choice |
|---|---|
| Language / framework | PHP 8.3, Laravel 12 |
| Database | PostgreSQL 16 (Neon in production) |
| Auth | Laravel Sanctum bearer tokens (30 days), Google sign-in via Socialite |
| Email | Laravel mailer: `log` locally, Brevo API in production |
| Tests / style | Pest, Laravel Pint, Larastan (PHPStan) |
| Hosting | Render (Docker), see [docs/DEPLOY.md](docs/DEPLOY.md) |

The API contract is [`docs/openapi.yaml`](docs/openapi.yaml). Every endpoint lives under `/api/v1`.

## Run it locally

### Option A: without Docker

You need PHP 8.3 (with `pdo_pgsql` or `pdo_sqlite`) and Composer.

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Pick a database in `.env`:

- **Quickest (SQLite):** set `DB_CONNECTION=sqlite`, leave `DB_URL` empty, then run `touch database/database.sqlite`.
- **Like production (Postgres):** keep `DB_CONNECTION=pgsql` and set `DB_URL=pgsql://user:password@127.0.0.1:5432/loomora`.

Then:

```bash
php artisan migrate
php artisan serve
curl http://127.0.0.1:8000/api/v1/health
# {"status":"ok"}
```

### Option B: with Docker

```bash
docker network create loomora
docker run -d --name loomora-db --network loomora \
  -e POSTGRES_USER=loomora -e POSTGRES_PASSWORD=loomora -e POSTGRES_DB=loomora postgres:16
docker build -t loomora-api .
docker run --rm --network loomora -p 10000:10000 \
  -e APP_KEY="base64:$(openssl rand -base64 32)" \
  -e DB_CONNECTION=pgsql \
  -e DB_URL=pgsql://loomora:loomora@loomora-db:5432/loomora \
  loomora-api
curl http://127.0.0.1:10000/api/v1/health?deep=1
# {"status":"ok","database":"ok"}
```

The container runs the migrations on start and stops with `FATAL: migrations failed` if it cannot reach the database.

## Run the checks

```bash
php artisan test              # Pest (in-memory SQLite by default)
./vendor/bin/pint --test      # code style
./vendor/bin/phpstan analyse  # static analysis
npx @redocly/cli lint docs/openapi.yaml   # API contract
```

CI (GitHub Actions) runs Pint, PHPStan and the tests against Postgres 16 on every pull request, and builds the Docker image.

## Environment variables

Values are never committed. Locally they go in `.env`; in production they go in the Render dashboard.

| Name | What it is |
|---|---|
| `APP_KEY` | Laravel's encryption key (`base64:...`). Encrypts the Google sign-in state and signs email links. |
| `APP_URL` | Public URL of this API, e.g. `https://loomora-api.onrender.com`. |
| `APP_DEBUG` | `true` only on your machine. Leave it unset (false) in production so errors never show stack traces. |
| `FRONTEND_URL` | Web app origin(s) allowed by CORS and used in email links. Comma-separated, no trailing slash. |
| `DB_CONNECTION` | `pgsql` (or `sqlite` locally). |
| `DB_URL` | Database connection string. In production: Neon's **direct** (not pooled) connection string. |
| `GOOGLE_CLIENT_ID` | Google OAuth client ID. |
| `GOOGLE_CLIENT_SECRET` | Google OAuth client secret. |
| `GOOGLE_REDIRECT_URI` | `https://<api-host>/api/v1/auth/google/callback`. |
| `MAIL_MAILER` | `log` locally (emails go to `storage/logs`), `brevo` in production. |
| `BREVO_API_KEY` | Brevo API key (only needed when `MAIL_MAILER=brevo`). |
| `MAIL_FROM_ADDRESS` | Sender address. Must be a sender verified in Brevo. |
| `FEEDBACK_TO_EMAIL` | Where feedback messages are emailed. |

## Docs

Planning:
- [01 Product brief](docs/01-product-brief.md)
- [02 Feature list](docs/02-feature-list.md)
- [03 User stories (R1)](docs/03-user-stories-r1.md)
- [04 User flows](docs/04-user-flows.md)
- [05 Screen map](docs/05-screen-map.md)
- [06 Roadmap and automation](docs/06-roadmap-and-automation.md)
- [07 Project decisions](docs/07-project-decisions.md)
- [08 Data model](docs/08-data-model.md)
- [09 API design](docs/09-api-design.md)
- [10 Tech and structure](docs/10-tech-and-structure.md)

Building and running:
- [API contract (OpenAPI)](docs/openapi.yaml)
- [Deployment guide](docs/DEPLOY.md)

Working files:
- [Tasks](docs/TASKS.md)
- [Progress](docs/PROGRESS.md)
- [Decisions](docs/DECISIONS.md)
- [Security findings](docs/SECURITY-FINDINGS.md)

Rules for AI-assisted work: [CLAUDE.md](CLAUDE.md)
