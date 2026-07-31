<?php

/**
 * The smoke test that runs on the oldest PHP the library claims to support.
 *
 * Not a second test suite, and it must never grow into one - PHPUnit is not available down here and the
 * point is only to catch the obvious: the library parses, it loads, and a session still round-trips.
 * Anything finer belongs in the real suite, which runs from 7.3 upward.
 *
 * Written to 5.6 syntax - no short array syntax, no "??", nothing PHPUnit. It has to run where it is aimed.
 *
 * Run through tests/run-legacy.sh rather than directly.
 */

// session_start() sends headers, and everything printed below counts as output having begun. The buffer is
// flushed when the script ends, so the report still reaches the terminal
ob_start();

$failures = 0;
$checks   = 0;

/**
 * @param   string  $what       what is being claimed
 * @param   bool    $condition  whether it holds
 *
 * @return  void
 */
function check($what, $condition) {

    global $failures, $checks;

    $checks++;

    if ($condition) {
        echo '  ok    ' . $what . "\n";
    } else {
        echo '  FAIL  ' . $what . "\n";
        $failures++;
    }

}

echo 'PHP ' . PHP_VERSION . "\n\n";

require_once dirname(dirname(__DIR__)) . '/Zebra_Session.php';

check('the library class exists', class_exists('Zebra_Session'));

$host  = getenv('DB_HOST') !== false ? getenv('DB_HOST') : '127.0.0.1';
$port  = getenv('DB_PORT') !== false ? (int)getenv('DB_PORT') : 3306;
$user  = getenv('DB_USER') !== false ? getenv('DB_USER') : 'root';
$pass  = getenv('DB_PASS') !== false ? getenv('DB_PASS') : '';
$name  = getenv('DB_NAME') !== false ? getenv('DB_NAME') : 'zebra_session_tests';
$table = getenv('DB_TABLE') !== false ? getenv('DB_TABLE') : 'zebra_session_test_data';

$link = @new mysqli($host, $user, $pass, $name, $port);

if ($link->connect_error) {
    echo '  FAIL  could not reach MySQL at ' . $host . ':' . $port . ' - ' . $link->connect_error . "\n";
    exit(1);
}

$link->set_charset('utf8mb4');

// the server here is a throwaway started by run-legacy.sh, so the table is this script's to make. The
// definition is a copy of install/session_data.sql, like the one in tests/bootstrap.php
$link->query('DROP TABLE IF EXISTS `' . $table . '`');
$link->query('
    CREATE TABLE `' . $table . '` (
        `session_id`        varchar(32) NOT NULL default \'\',
        `hash`              varchar(32) NOT NULL default \'\',
        `session_data`      mediumblob NOT NULL,
        `session_expire`    int(11) NOT NULL default \'0\',
        PRIMARY KEY (`session_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
');

$session_id = 'legacy-smoke-session';

// the session id normally arrives in a cookie, and there is none in CLI
$_COOKIE[session_name()] = $session_id;

$payload = 'written on ' . PHP_VERSION;

$session = new Zebra_Session($link, 'sec-code', 3600, true, false, 60, $table);

check('a session starts', session_id() === $session_id);

$_SESSION['smoke'] = $payload;

session_write_close();

$result = $link->query('SELECT session_data FROM `' . $table . '` WHERE session_id = \'' . $session_id . '\'');
$row    = $result->fetch_assoc();

check('the session reached the table', $row !== null);
check('and holds what was put in it', $row !== null && strpos($row['session_data'], $payload) !== false);

$link->close();

echo "\n" . ($failures === 0
    ? $checks . " checks, all fine\n"
    : $failures . ' of ' . $checks . " checks failed\n");

exit($failures === 0 ? 0 : 1);
