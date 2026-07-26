#!/bin/sh
# for running the tests a MySQL instance is needed
# copy phpunit.xml.dist to phpunit.xml (git-ignored) and put your connection details there
# phpunit uses phpunit.xml when it exists and falls back to phpunit.xml.dist otherwise

if ! command -v php > /dev/null 2>&1; then
    echo "php was not found in PATH."
    echo "Add it, for example: PATH=\"/path/to/php/bin:\$PATH\" sh run-tests.sh"
    exit 1
fi

if [ ! -f vendor/bin/phpunit ]; then
    echo "vendor/bin/phpunit was not found - run 'composer install' first."
    exit 1
fi

if [ ! -f phpunit.xml ]; then
    echo "phpunit.xml was not found, so the defaults from phpunit.xml.dist are used and they will most likely not"
    echo "match your setup. The tests run against a real MySQL instance."
    echo
    echo "Copy phpunit.xml.dist to phpunit.xml (it is git-ignored) and set:"
    echo "  RUN_DB_TESTS     must be true/1/yes/on to run the database tests"
    echo "  DB_HOST          host of the MySQL instance"
    echo "  DB_PORT          port of the MySQL instance"
    echo "  DB_NAME          an existing database - tables are created in and dropped from it"
    echo "  DB_USER          user with CREATE/DROP rights on that database"
    echo "  DB_PASS          password for that user"
    echo "  DB_TABLE         table used by the suite - must contain \"test\", it is dropped"
    echo "  TEST_SESSION_ID  session id used by the spawned helper processes"
    echo
fi

# the compatibility check runs before the coding standards one because that one currently ends with a non-zero status
vendor/bin/phpunit && echo && vendor/bin/phpstan analyse && echo && vendor/bin/phpcs --standard=php-compatibility.xml && echo && vendor/bin/phpcs --standard=coding-standards.xml
