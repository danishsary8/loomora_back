#!/usr/bin/env bash
set -euo pipefail

container=${1:?Pass the disposable production API container name}
base_url=${2:?Pass the disposable API base URL}
logs=$(mktemp -d)
trap 'rm -f "$logs"/stdout "$logs"/stderr "$logs"/files; rmdir "$logs"' EXIT

request() {
    local expected=$1 actual
    shift
    actual=$(curl --silent --show-error --max-time 10 --output /dev/null \
        --write-out '%{http_code}' --header 'Accept: application/json' \
        --header 'Connection: close' "$@")
    if [[ "$actual" != "$expected" ]]; then
        echo "Expected HTTP $expected, got $actual" >&2
        return 1
    fi
}

request 200 "$base_url/api/v1/health?probe=b041-fixture-query" \
    --head \
    --header 'Referer: https://example.invalid/?probe=b041-fixture-referer' \
    --header 'Authorization: Bearer b041-fixture-authorization' \
    --header 'Cookie: probe=b041-fixture-cookie'
request 302 "$base_url/api/v1/auth/google/callback?code=b041-fixture-oauth-code&state=b041-fixture-oauth-state"
request 401 "$base_url/api/v1/me?probe=b041-fixture-rejected-query"

# A directory with no index triggers an Apache diagnostic at the normal log level.
fixture=$(docker exec "$container" mktemp -d public/b041-log-probe.XXXXXXXX)
trap 'docker exec "$container" rmdir "$fixture"; rm -f "$logs"/stdout "$logs"/stderr "$logs"/files; rmdir "$logs"' EXIT
docker exec "$container" chmod 755 "$fixture"
request 400 "$base_url/api/v1/health?probe=b041-fixture-malformed-query" \
    --http1.1 --header 'Host:' \
    --header 'Referer: https://example.invalid/?probe=b041-fixture-malformed-referer'
request 403 "$base_url/${fixture#public/}/?probe=b041-fixture-forbidden-query" \
    --header 'Referer: https://example.invalid/?probe=b041-fixture-forbidden-referer'

# Apache writes the access record after sending the response. Wait for the last probe.
for attempt in {1..10}; do
    docker logs "$container" >"$logs/stdout" 2>"$logs/stderr"
    if grep -F "/${fixture#public/}/" "$logs/stdout" | grep -Eq ' 403 [0-9-]+( |$)'; then
        break
    fi
    sleep 1
done

# Apache's standard log files point to the container streams. Include any regular
# files as well to catch a duplicate sink and Laravel's default file channel.
docker exec "$container" sh -c '
    set -e
    for log in /var/log/apache2/* /var/www/html/storage/logs/*; do
        if [ -f "$log" ] && [ ! -L "$log" ]; then
            cat "$log"
        fi
    done
' >"$logs/files"

exposed=0
for marker in query referer authorization cookie oauth-code oauth-state rejected-query \
    forbidden-query forbidden-referer malformed-query malformed-referer; do
    for stream in stdout stderr files; do
        if grep -Fq "b041-fixture-$marker" "$logs/$stream"; then
            echo "Exposed synthetic $marker marker in $stream" >&2
            exposed=1
        fi
    done
done
if (( exposed )); then
    exit 1
fi

# The unique 403 probe must produce one access record across the collected sinks.
forbidden_record=" GET /${fixture#public/}/ 403 "
if [[ $(grep -Fc "$forbidden_record" "$logs/stdout" || true) != 1 ]] \
    || grep -Fq "$forbidden_record" "$logs/stderr" "$logs/files"; then
    echo 'Expected one 403 access record on stdout only' >&2
    exit 1
fi

# Match the entire record so added request fields break this check.
grep -E '^\[[^]]+\] HEAD /api/v1/health 200 [0-9]+$' "$logs/stdout"
grep -E '^\[[^]]+\] GET /api/v1/auth/google/callback 302 [0-9]+$' "$logs/stdout"
grep -E '^\[[^]]+\] GET /api/v1/me 401 [0-9]+$' "$logs/stdout"
grep -E '^\[[^]]+\] GET /b041-log-probe\.[[:alnum:]]{8}/ 403 [0-9]+$' "$logs/stdout"
grep -E '^\[[^]]+\] (GET|-) (/api/v1/health|-) 400 [0-9]+$' "$logs/stdout"
grep -Fq 'AH01276: Cannot serve directory' "$logs/stderr"
echo 'Access-log regression passed'
