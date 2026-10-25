#!/bin/sh
set -eu

# Render passes the port to listen on in $PORT.
port="${PORT:-10000}"
sed -ri "s/^Listen [0-9]+$/Listen ${port}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:[0-9]+>/<VirtualHost *:${port}>/" /etc/apache2/sites-available/000-default.conf

php artisan config:cache
php artisan route:cache

# Neon suspends when idle, so the first connection after a deploy can fail
# while it wakes up. Retry a few times, then fail the container loudly.
export PGCONNECT_TIMEOUT="${PGCONNECT_TIMEOUT:-20}"
attempt=1
until php artisan migrate --force --no-interaction; do
    if [ "$attempt" -ge 3 ]; then
        echo "[loomora] FATAL: migrations failed after ${attempt} attempts, refusing to start" >&2
        exit 1
    fi
    echo "[loomora] migration attempt ${attempt} failed, retrying in 5s" >&2
    attempt=$((attempt + 1))
    sleep 5
done

exec "$@"
