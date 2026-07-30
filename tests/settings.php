<?php

/**
 * The settings the test suite runs with, and the helpers that read them.
 *
 * This file only declares things - no connections, no directories created, nothing written. That is what
 * lets anything include it safely, which phpstan needs: the only way it learns a constant exists is by
 * executing the define(), and pointing it at bootstrap.php would have it connect to MySQL.
 *
 * bootstrap.php includes this file and then does the work that has side effects.
 */

/**
 * Reads a setting from the environment.
 *
 * We cannot use "?:" here - an empty value is perfectly valid for some of these (an empty password, most
 * obviously) and "?:" would silently replace it with the fallback. Only a value that is not set at all
 * may fall back.
 *
 * @param   string  $name       name of the environment variable, as set in phpunit.xml
 * @param   mixed   $default    what to use when it is not set at all
 *
 * @return  mixed
 */
function test_env($name, $default) {
    $value = getenv($name);
    return $value === false ? $default : $value;
}

// connection credentials - see tests/phpunit.xml.dist
define('TEST_DB_HOST', test_env('DB_HOST', '127.0.0.1'));
define('TEST_DB_USER', test_env('DB_USER', 'root'));
define('TEST_DB_PASS', test_env('DB_PASS', ''));
define('TEST_DB_NAME', test_env('DB_NAME', 'zebra_session_tests'));
// cast so that the constant has one type rather than two - getenv() hands back a string and the fallback
// is a number. The callers cast it back to an int where a connection wants one.
define('TEST_DB_PORT', (string)test_env('DB_PORT', 3306));

// the table the handler stores sessions in. bootstrap.php drops it, and refuses any name without "test" in
// it - the guard between a typo in phpunit.xml and somebody's real sessions table
define('TEST_DB_TABLE', test_env('DB_TABLE', 'zebra_session_test_data'));

// the session id the helper processes use. There is no browser here to hand one over in a cookie, so every
// helper is told which session it is working with
define('TEST_SESSION_ID', test_env('TEST_SESSION_ID', 'zebra-unit-test-session'));

// the helper the tests drive - one request, in a process of its own, configured through its environment
define('TEST_SESSION_HELPER', __DIR__ . '/Fixtures/sessionTestHelper.php');

// paths the suite reads from and writes to
define('TEST_TMP_PATH', __DIR__ . '/tmp');

/**
 * Returns a path under tmp/, creating it if it is not there yet.
 *
 * @param   string  $subdir     optional subdirectory of tmp/
 *
 * @return  string
 */
function getTempPath($subdir = '') {

    $path = TEST_TMP_PATH;

    if ($subdir) {
        $path .= '/' . trim($subdir, '/');
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    return $path;

}

/**
 * Removes whatever the tests left behind in tmp/.
 *
 * @return  void
 */
function cleanupTempFiles() {
    $files = glob(TEST_TMP_PATH . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        } elseif (is_dir($file) && basename($file) !== '.gitkeep') {
            $sub_files = glob($file . '/*');
            foreach ($sub_files as $sub_file) {
                if (is_file($sub_file)) {
                    unlink($sub_file);
                }
            }
        }
    }
}
