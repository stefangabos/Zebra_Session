#!/usr/bin/env bash
#
# Runs the test suite.
#
# Settings come from phpunit.xml if present, or from phpunit.xml.dist otherwise - see the comments in
# phpunit.xml.dist for how to point the suite at a setup of your own.
#
# Run it with no arguments and it checks everything - the suite, then the compatibility, static analysis
# and coding standard checks. Give it any argument and it runs only the tests you asked for, because that
# is the case where you are working on something and do not want to wait for the rest:
#
#   tests/run-tests.sh                                  the suite and all three checks
#   tests/run-tests.sh --testdox                        readable output, tests only
#   tests/run-tests.sh --filter something               only tests matching "something"
#   tests/run-tests.sh LockingTest.php                  a single file
#   tests/run-tests.sh --group regression               every test guarding a fix
#   tests/run-tests.sh --coverage-html coverage-html    with a coverage report (needs xdebug or pcov)
#   tests/run-tests.sh --static                         the checks as well, whatever else is given
#
# The three checks are also available on their own, and those are the ones to use while working through
# what they report, since they take arguments of their own:
#
#   composer check-compat / check-compat-legacy / analyse / check-style
#
# The suite needs PHP 7.4 or newer. The library works further back than that, and tests/run-legacy.sh is
# what backs that claim up.
#
# Set PHP to use an interpreter that is not the one on the PATH - handy for checking the suite
# against another version, and required on setups where "php" is not on the PATH at all:
#
#   PHP=/path/to/php7.4/bin/php tests/run-tests.sh
#

set -euo pipefail

cd "$(dirname "$0")"

# a run starts on a clean screen, unless the output is going somewhere other than a terminal
if [ -t 1 ]; then clear; fi

PHP="${PHP:-php}"

# with nothing asked for in particular, check everything
RUN_STATIC=$([ $# -eq 0 ] && echo 1 || echo 0)
ARGS=()

for argument in "$@"; do
    if [ "$argument" = "--static" ]; then RUN_STATIC=1; else ARGS+=("$argument"); fi
done

# a heading is a rule filling the terminal, with the name set into it. Bold only when this is a terminal
# that wants colour - a redirected run and a CI log get the same line without the escapes
if [ -t 1 ] && [ -z "${NO_COLOR:-}" ] && [ "${TERM:-dumb}" != "dumb" ]; then
    BOLD=$(printf '\033[1m')
    PLAIN=$(printf '\033[0m')
else
    BOLD=""
    PLAIN=""
fi

WIDTH=$( (command -v tput > /dev/null 2>&1 && tput cols) 2>/dev/null || echo 80)

# phpunit and phpstan work out their own widths, and phpstan's result banner is drawn across the whole of it.
# COLUMNS is what both of them read first, so setting it lines their output up with the rules drawn below
export COLUMNS=$WIDTH

# the rule is drawn with U+2550 where the terminal can take it, and with "=" where it cannot - a locale
# that is not UTF-8 would otherwise print three replacement characters per character of rule
case "${LC_ALL:-${LC_CTYPE:-${LANG:-}}}" in
    *UTF-8*|*utf8*|*UTF8*) BAR='═' ;;
    *)                     BAR='=' ;;
esac

RULE=''
remaining=$WIDTH
while [ "$remaining" -gt 0 ]; do
    RULE="$RULE$BAR"
    remaining=$(( remaining - 1 ))
done

heading() {

    printf '\n%s%s\n  %s\n%s%s\n' "$BOLD" "$RULE" "$1" "$RULE" "$PLAIN"

}

if ! command -v "$PHP" > /dev/null 2>&1; then
    echo "PHP interpreter '$PHP' not found - put php on your PATH or set PHP=/path/to/php." >&2
    exit 1
fi

if [ ! -f ../vendor/bin/phpunit ]; then
    echo "PHPUnit not found - run 'composer install' in the project root first." >&2
    exit 1
fi

echo "Using $("$PHP" -r 'echo PHP_BINARY, " (", PHP_VERSION, ")";')"

if [ "$RUN_STATIC" = "0" ]; then
    exec "$PHP" ../vendor/bin/phpunit ${ARGS[@]+"${ARGS[@]}"}
fi

# a failing suite must not stop the checks below - the point of --static is to see everything in one go -
# so the status is kept and returned at the end instead
test_result=0
"$PHP" ../vendor/bin/phpunit ${ARGS[@]+"${ARGS[@]}"} || test_result=$?

# the static analysis runs from the project root, where its configuration lives - phpstan.neon and
# coding-standards.xml both name paths relative to it
cd ..

# each of these ends with a non-zero status while there is anything left to fix, which under "set -e"
# would stop the script before it got to the next one
heading 'PHP COMPATIBILITY'
"$PHP" vendor/bin/phpcs -p --standard=tests/php-compatibility.xml --runtime-set ignore_warnings_on_exit 1 || true

heading 'STATIC ANALYSIS'
"$PHP" vendor/bin/phpstan analyse --no-progress || true

heading 'CODING STANDARD'
"$PHP" vendor/bin/phpcs -p --standard=coding-standards.xml --report=summary || true

# the PHP 5.6 check needs Docker, so it runs from tests/run-legacy.sh rather than from here
heading 'PHP 5.6'
echo "  not run - it needs Docker, so it is kept out of this script."
echo "  run tests/run-legacy.sh before tagging a release."

# the suite's result is what decides whether this run passed - the findings are work in progress
exit $test_result
