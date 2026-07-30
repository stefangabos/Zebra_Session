<?php

/**
 * Bootstrap for the test suite - loads the library and the support classes, reads the configuration, and
 * prepares whatever the tests need to exist before they run.
 *
 * PHPUnit is pointed at this file by tests/phpunit.xml.dist.
 */

// the library under test
require_once __DIR__ . '/../Zebra_Session.php';

// support classes (also listed in composer.json under autoload-dev, this is for running phpunit directly)
require_once __DIR__ . '/Support/SessionTestCase.php';
require_once __DIR__ . '/Support/ChildProcess.php';
require_once __DIR__ . '/Support/ChildProcessHandle.php';

// the settings and the helpers - declarations only, no side effects, which is what lets phpcs and phpstan
// read them without any of the setup below running
require_once __DIR__ . '/settings.php';


// the scratch directories - "child" holds the scripts ChildProcess writes. Everything the suite writes goes
// under here, so that it is all cleaned up together and nothing depends on there being a /tmp
foreach ([TEST_TMP_PATH, TEST_TMP_PATH . '/child'] as $path)
    if (!is_dir($path)) mkdir($path, 0777, true);

register_shutdown_function('cleanupTempFiles');

/* ---------------------------------------------------------------------------------------------------------
 * DATABASE SETUP
 *
 * The table is created once for the whole run. Each test starts from an empty one - see resetState() in the
 * base class.
 * ------------------------------------------------------------------------------------------------------ */

// the table below is dropped, so refuse any name that is not obviously a test table - a DB_TABLE pointing at
// a live "sessions" table would be destroyed by simply running phpunit
if (strpos(TEST_DB_TABLE, 'test') === false) {
    echo 'DB_TABLE must contain "test" - it is dropped by this suite. Got: ' . TEST_DB_TABLE . "\n";
    exit(1);
}

try {

    $connection = new mysqli(TEST_DB_HOST, TEST_DB_USER, TEST_DB_PASS, '', (int)TEST_DB_PORT);

    if ($connection->connect_error) throw new Exception('Could not connect to MySQL: ' . $connection->connect_error);

    // the table is utf8mb4, so this connection has to be as well, or the rows written here go in through
    // whatever the server's default happens to be
    $connection->set_charset('utf8mb4');

    $connection->query('CREATE DATABASE IF NOT EXISTS `' . TEST_DB_NAME . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $connection->select_db(TEST_DB_NAME);

    // dropped before being created, so that a table left over from an older checkout cannot keep whatever
    // shape it had then. The definition is a copy of install/session_data.sql - keep the two in step, or the
    // suite passes against a schema no user of the library has.
    $connection->query('DROP TABLE IF EXISTS `' . TEST_DB_TABLE . '`');

    $connection->query('
        CREATE TABLE `' . TEST_DB_TABLE . '` (
            `session_id`        varchar(32) NOT NULL default \'\',
            `hash`              varchar(32) NOT NULL default \'\',
            `session_data`      mediumblob NOT NULL,
            `session_expire`    int(11) NOT NULL default \'0\',
            PRIMARY KEY (`session_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $connection->close();

} catch (Exception $e) {

    echo 'Test environment setup failed: ' . $e->getMessage() . "\n";
    echo 'Check the settings in tests/phpunit.xml - see tests/phpunit.xml.dist for what they are.' . "\n";
    exit(1);

}

/* --------------------------------------------------------------------------------------------------------
 * END OF THE DATABASE SETUP
 * ------------------------------------------------------------------------------------------------------ */
