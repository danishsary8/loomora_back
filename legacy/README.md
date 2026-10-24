# Daycraft API

Laravel 12 API for Daycraft. It provides Sanctum bearer authentication, verified-email schedule mutations, Google OAuth, password recovery, user-owned day groups, dated checklist logs, notification settings, and optional cached prayer times.

## Run locally

```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

Use PostgreSQL for deployed environments. SQLite is supported by the automated test configuration.

## Quality checks

```bash
php artisan test
vendor/bin/pint --test
composer audit
```

See [`API.md`](API.md) for the HTTP contract, [`../CONTRIBUTING.md`](../CONTRIBUTING.md) for architecture rules, and [`../DEPLOYMENT.md`](../DEPLOYMENT.md) for environment configuration.
