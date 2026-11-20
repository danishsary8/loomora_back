#!/usr/bin/env bash
set -euo pipefail

# Never inherit a deployment connection or publish the application or database.
if [[ $# != 1 || $1 != --disposable-postgres ]]; then
    echo 'Usage: bash tests/Support/cutover/rehearse.sh --disposable-postgres' >&2
    exit 2
fi
[[ -z ${DOCKER_HOST:-} ]] || { echo 'Select a local Docker context and unset DOCKER_HOST.' >&2; exit 2; }
endpoint=$(docker context inspect "$(docker context show)" --format '{{.Endpoints.docker.Host}}')
case "$endpoint" in
    unix:///*|npipe:////./pipe/*) ;;
    *) echo 'A local Docker socket is required.' >&2; exit 2 ;;
esac
export MSYS_NO_PATHCONV=1
cd "$(git rev-parse --show-toplevel)"
old=24d4e7225fe212dfe73e41be9827e8509fe625ac
candidate=1188595d4aa0b3c5ab2638b4ee6db0d2829640e7
run="loomora-b038-$(date +%s)-$$"
build_revision() {
    git archive "$1" | docker build --quiet -
}
old_image=$(build_revision "$old")
candidate_image=$(build_revision "$candidate")

net=$(docker network create --internal "$run")
containers=()
cleanup() {
    for id in "${containers[@]}"; do docker rm -fv "$id" >/dev/null 2>&1 || true; done
    docker network rm "$net" >/dev/null
}
trap cleanup EXIT
db=$(docker run -d --network "$net" --network-alias cutover-db --tmpfs /var/lib/postgresql/data \
    -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_DB=b038_cutover postgres:16)
containers+=("$db")
db_deadline=$((SECONDS + 45))
until docker exec "$db" pg_isready -h 127.0.0.1 -U postgres -d b038_cutover >/dev/null; do
    [[ $(docker inspect --format '{{.State.Running}}' "$db") = true ]] || exit 1
    (( SECONDS < db_deadline )) || exit 1
    sleep 1
done
key=$(docker run --rm --network none --entrypoint php "$candidate_image" \
    -r "echo 'base64:'.base64_encode(random_bytes(32));")
for phase in seed verify; do
    image=$old_image
    if [[ $phase = verify ]]; then image=$candidate_image; fi
    api=$(docker run -d --network "$net" -e APP_ENV=production -e APP_DEBUG=false -e APP_KEY="$key" \
        -e DB_CONNECTION=pgsql -e DB_HOST=cutover-db -e DB_DATABASE=b038_cutover -e DB_USERNAME=postgres \
        -e MAIL_MAILER=array -e LOG_CHANNEL=null -e CACHE_STORE=database -e B038_DISPOSABLE=yes \
        "$image")
    containers+=("$api")
    ready=0
    for _ in {1..45}; do
        if docker exec "$api" php -r 'exit(@file_get_contents("http://127.0.0.1:10000/api/v1/health?deep=1") === false ? 1 : 0);'; then
            ready=1; break
        fi
        [[ $(docker inspect --format '{{.State.Running}}' "$api") = true ]] || break
        sleep 1
    done
    [[ $ready = 1 ]] || { echo "Deep health unavailable during $phase" >&2; exit 1; }
    docker cp tests/Support/cutover/fixture.php "$api:/tmp/b038-fixture.php"
    result=$(docker exec "$api" php /tmp/b038-fixture.php "$phase")
    [[ $result = PASS* ]] || { echo "Missing fixture evidence during $phase" >&2; exit 1; }
    echo "$result"
    if [[ $phase = verify ]]; then
        docker stop "$db" >/dev/null
        docker exec "$api" php -r '
            $context = stream_context_create(["http" => ["method" => "POST", "ignore_errors" => true,
                "timeout" => 15, "header" => "Accept: application/json\r\nContent-Length: 0"]]);
            $body = file_get_contents("http://127.0.0.1:10000/api/v1/auth/login", false, $context);
            exit(str_contains($http_response_header[0], " 500 ") && json_decode($body, true) === ["message" => "Server Error"] ? 0 : 1);'
        echo 'PASS production HTTP 500 during a disposable database outage'
    fi
    docker stop "$api" >/dev/null
done
