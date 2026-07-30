#!/usr/bin/env bash
#
# Checks the library on the oldest PHP it claims to support.
#
# The suite itself stops at 7.3, which is as far back as PHPUnit 9.6 goes, while the README says the library
# works on 5.5.2. This is what backs that claim: a lint pass over everything that ships, and a smoke script
# that round-trips one session on a real PHP 5.6, against a MySQL of its own.
#
#   tests/run-legacy.sh                 lint, then the smoke script
#   tests/run-legacy.sh --lint          the lint alone, which needs no database
#
# It runs in a container because there is no arm64 build of PHP 5.6 - MAMP ships one, but it links against
# OpenSSL 1.0, readline 6 and OpenLDAP 2.4, none of which exist on a current macOS. The image is built once
# and cached; the first run takes a few minutes.

set -euo pipefail

cd "$(dirname "$0")"

IMAGE="zebra-session-legacy-php56"
LINT_ONLY=0

for argument in "$@"; do
    if [ "$argument" = "--lint" ]; then LINT_ONLY=1; fi
done

if ! command -v docker > /dev/null 2>&1; then
    echo "docker was not found - it is what provides the PHP 5.6 this check needs." >&2
    exit 1
fi

if ! docker info > /dev/null 2>&1; then
    echo "The docker daemon is not running - start Docker Desktop and try again." >&2
    exit 1
fi

# the smoke test brings its own MySQL rather than using the one the suite runs against. PHP 5.6's client does
# not know the collation MySQL 8 defaults to - utf8mb4_0900_ai_ci - and the connection dies during the
# handshake with "Server sent charset unknown to the client", before any client option can be applied. A
# server of the era the library's floor belongs to is both the honest test and the one that works anywhere.
MYSQL_IMAGE="mysql:5.7"
MYSQL_CONTAINER="zebra-session-legacy-mysql"
NETWORK="zebra-session-legacy"

DB_HOST="$MYSQL_CONTAINER"
DB_PORT="3306"
DB_USER="root"
DB_PASS=""
DB_NAME="zebra_session_tests"
DB_TABLE="zebra_session_test_data"

if ! docker image inspect "$IMAGE" > /dev/null 2>&1; then
    echo "Building the PHP 5.6 image - this happens once and takes a few minutes."
    docker build --platform linux/amd64 -t "$IMAGE" legacy
fi

# the library root, so that the source streamed below is the whole of it
cd ..

# the source goes in through a tar on stdin rather than a bind mount. Docker Desktop shares only a handful
# of paths by default - /Users, /Volumes, /private, /tmp - and a library kept anywhere else would need
# whoever runs this to add it under Resources - File Sharing first
run_in_container() {
    # COPYFILE_DISABLE and --no-xattrs keep macOS from putting an AppleDouble "._" twin of every file in
    # the archive - those are not PHP and the lint below would try to parse them
    COPYFILE_DISABLE=1 tar -cf - --no-xattrs \
        --exclude=./vendor --exclude=./node_modules --exclude=./.git --exclude=./docs --exclude=./tests/mutate.php . \
        | docker run -i --rm --platform linux/amd64 ${2:+--network "$NETWORK"} \
            -e DB_HOST="$DB_HOST" -e DB_PORT="$DB_PORT" -e DB_USER="$DB_USER" \
            -e DB_PASS="$DB_PASS" -e DB_NAME="$DB_NAME" -e DB_TABLE="$DB_TABLE" \
            "$IMAGE" sh -c "tar -xf - -C /library && $1"
}

# whatever happens below, take the server and the network back down
cleanup() {
    docker rm -f "$MYSQL_CONTAINER" > /dev/null 2>&1 || true
    docker network rm "$NETWORK" > /dev/null 2>&1 || true
}

echo
echo "--- lint ---"

# everything that ships, not only the main file - a parse error in an include is just as fatal
run_in_container 'for file in $(find . -name "*.php" -not -path "./vendor/*" -not -path "./tests/*" -not -path "./node_modules/*"); do php -l "$file" || exit 1; done'

if [ "$LINT_ONLY" = "1" ]; then
    echo
    echo "lint only - the smoke test was skipped"
    exit 0
fi

echo
echo "--- smoke ---"

trap cleanup EXIT
cleanup

docker network create "$NETWORK" > /dev/null

echo "starting ${MYSQL_IMAGE} - the first run pulls it"
docker run -d --rm --name "$MYSQL_CONTAINER" --network "$NETWORK" --platform linux/amd64 \
    -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_DATABASE="$DB_NAME" \
    "$MYSQL_IMAGE" > /dev/null

printf 'waiting for it to accept connections'

# over TCP, not the socket. The entrypoint runs a temporary socket-only server while it initialises the
# data directory, and a plain "mysqladmin ping" answers from that one long before the port is open
ready=0
for _ in $(seq 1 90); do
    if docker exec "$MYSQL_CONTAINER" mysqladmin --protocol=TCP -h 127.0.0.1 ping --silent > /dev/null 2>&1; then ready=1; break; fi
    printf '.'
    sleep 2
done
echo

if [ "$ready" = "0" ]; then
    echo "MySQL never became available." >&2
    exit 1
fi

run_in_container 'php tests/legacy/smoke.php' network
