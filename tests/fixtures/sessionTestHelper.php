<?php
/**
 * Script used to simulate different scenarios of using the session.
 * Starts a session with Zebra_Session handler.
 * The idea is to call this script multiple times in parallel to test if the handler works properly.
 * Intended for following scenarios:
 * - imitate long-running request with the session locked (session is closed after the "task" is done)
 * - opens read-only session and print data from session (should work with a locked session)
 * - open read-only session and write some data (no error, data should not be saved)
 *
 */

require_once __DIR__ . '/../../Zebra_Session.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// Configuration
$sid = getenv('TEST_SESSION_ID') ?: 'zebra-unit-test-session';
$readOnly = (getenv('READ_ONLY') == 'yes' );
$writeDataToSession = getenv('WRITE_DATA_TO_SESSION');
$readData = (getenv('READ_DATA_FROM_SESSION') == 'yes');
$startLongTask = (getenv('START_LONG_TASK') == 'yes');

// Establishing connection to the DB
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'test_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'secret';
$dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

try {
    $pdo = new \PDO($dsn, $user, $pass, [
        \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
    ]);
} catch (\PDOException $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// Setting up the handler
$handler = new \Zebra_Session($pdo,
    'sec-code',
    3600,
    true,
    false,
    60,
    'sessions',
    false,
    $readOnly
);

session_id($sid);
session_start();
// Printed text will be analysed by the tests. Also handy for debugging.
echo json_encode(['session_start' => $sid]);
echo json_encode(['readonly' => ($readOnly ? 'yes' : 'no')]);

// If requested, data is written to $_SESSION[$sid]
if (!empty($writeDataToSession)) {
    $_SESSION[$sid] = $writeDataToSession;
}

// If requested, the data is printed.
// Since the tests may not wait for the process to finish, we close and write the session before printing stored data.
// When the data is printed then it's guaranteed to be stored.
$dataToPrint =  json_encode([$sid => $_SESSION[$sid]]);

// Long-running task is supposed to lock the session.
if ($startLongTask) {
    $cycleCounter = 100;
    for ($i = 0; $i < $cycleCounter; $i++) {
        sleep(1);
        // Sleep until the counter runs out or the sessions table no longer exists (the unit test has ended but failed to kill the process)
        try {
            $result = $pdo->query("SELECT 1 FROM `sessions` LIMIT 1");
        } catch (Exception $e) {
            // Table not found
            break;
        }
    }
}

session_write_close();

if ($readData) {
    echo $dataToPrint;
}