<?php
/**
 * Starts a normal session with Zebra_Session handler.
 * Operates in two modes:
 * - imitates long-running request with the session locked (not closed)
 * - opens read-only session (should work with a locked session)
 *
 * The idea is to call this script multiple times in parallel to test if session is properly locked.
 */

require_once __DIR__ . '/../../Zebra_Session.php';
require_once __DIR__ . '/../../vendor/autoload.php';

// Establishing connection to the DB
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'test_db';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: 'secret';
$sid = getenv('TEST_SESSION_ID') ?: 'zebra-unit-test-session';


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
$readOnly = (getenv('READ_ONLY') == 'yes' );

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
session_set_save_handler($handler, true);

session_id($sid);
session_start();
echo json_encode(['session_start' => $sid]);

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
