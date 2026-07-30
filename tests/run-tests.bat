@echo off
::
:: Runs the test suite. The Windows counterpart of run-tests.sh - keep the two in step.
::
:: Settings come from phpunit.xml if present, or from phpunit.xml.dist otherwise - see the comments in
:: phpunit.xml.dist for how to point the suite at a setup of your own.
::
:: Run it with no arguments and it checks everything. Give it any argument and it runs only the tests you
:: asked for, because that is the case where you are working on something and do not want to wait:
::
::   tests\run-tests.bat                                  the suite and all three checks
::   tests\run-tests.bat --testdox                        readable output, tests only
::   tests\run-tests.bat --filter something               only tests matching "something"
::   tests\run-tests.bat LockingTest.php                     a single file
::   tests\run-tests.bat --group regression               every test guarding a fix
::   tests\run-tests.bat --coverage-html coverage-html    with a coverage report (needs xdebug or pcov)
::   tests\run-tests.bat --static                         the checks as well, whatever else is given
::
:: The three checks that --static adds are also available on their own, and those are the ones to use
:: while working through what they report, since they take arguments:
::
::   composer check-compat / check-compat-legacy / analyse / check-style
::
:: Set PHP to use an interpreter that is not the one on the PATH - handy for checking the suite against
:: another version, and required on setups where "php" is not on the PATH at all:
::
::   set "PHP=C:\php\8.3\php.exe"
::   tests\run-tests.bat
::

setlocal enabledelayedexpansion

cd /d "%~dp0"

if "%PHP%"=="" set "PHP=php"

:: with nothing asked for in particular, check everything
set RUN_STATIC=0
if "%~1"=="" set RUN_STATIC=1
set "ARGS="

:parse_arguments
if "%~1"=="" goto arguments_parsed
if /i "%~1"=="--static" (
    set RUN_STATIC=1
) else (
    set "ARGS=!ARGS! %1"
)
shift
goto parse_arguments
:arguments_parsed

:: running it is a better check than "where", which only searches the PATH and would refuse a PHP set to
:: a full path like C:\php\8.3\php.exe
"%PHP%" -v >nul 2>nul
if errorlevel 1 (
    echo PHP interpreter "%PHP%" not found - put php on your PATH or set PHP=C:\path\to\php.exe
    exit /b 1
)

if not exist ..\vendor\bin\phpunit (
    echo PHPUnit not found - run "composer install" in the project root first.
    exit /b 1
)

for /f "delims=" %%V in ('"%PHP%" -r "echo PHP_BINARY . ' (' . PHP_VERSION . ')';"') do echo Using %%V

"%PHP%" ..\vendor\bin\phpunit%ARGS%
set TEST_RESULT=%errorlevel%

if "%RUN_STATIC%"=="0" exit /b %TEST_RESULT%

:: the static analysis runs from the project root, where its configuration lives - phpstan.neon and
:: coding-standards.xml both name paths relative to it
cd ..

call :heading "PHP COMPATIBILITY"
"%PHP%" vendor\bin\phpcs -p --standard=tests/php-compatibility.xml --runtime-set ignore_warnings_on_exit 1

call :heading "STATIC ANALYSIS"
"%PHP%" vendor\bin\phpstan analyse --no-progress

call :heading "CODING STANDARD"
"%PHP%" vendor\bin\phpcs -p --standard=coding-standards.xml --report=summary

exit /b %TEST_RESULT%

:: A banner with the name between two rules, the same shape run-tests.sh draws. "=" rather than the box
:: drawing character it uses, because what cmd makes of U+2550 depends on the console code page.
:heading
setlocal enabledelayedexpansion
set "rule="
for /l %%i in (1,1,80) do set "rule=!rule!="
echo.
echo !rule!
echo   %~1
echo !rule!
endlocal
goto :eof
