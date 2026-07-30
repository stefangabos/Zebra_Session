<?php

/**
 * Script used to simulate different scenarios of using the session.
 * Starts a session with Zebra_Session handler.
 * The idea is to call this script multiple times in parallel to test if the handler works properly.
 * Intended for following scenarios:
 * - imitate long-running request with the session locked (session is closed after the "task" is done)
 * - opens read-only session and print data from session (should work with a locked session)
 * - open read-only session and write some data (no error, data should not be saved)
 * - report the number of active sessions as seen by the handler
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
$getActiveSessions = (getenv('GET_ACTIVE_SESSIONS') == 'yes');
$destroySession = (getenv('DESTROY_SESSION') == 'yes');
$regenerateId = (getenv('REGENERATE_ID') == 'yes');
// the maximum lifetime handed to gc() - the library ignores it and goes by the stored session_expire instead, which is
// exactly what the tests pin down, so it has to be settable to something absurd
$runGc = getenv('RUN_GC');
// "name:value" - a flash data variable to set
$setFlashData = getenv('SET_FLASHDATA');
// the name of a flash data variable to print
$readFlashData = getenv('READ_FLASHDATA');
// let the constructor start the session (with the id passed in a cookie) instead of starting it here afterwards
$autostartSession = (getenv('AUTOSTART_SESSION') == 'yes');
// how long the library waits for the session lock - lowered by the test that checks what happens when it gives up
$lockTimeout = (int)(getenv('LOCK_TIMEOUT') ?: 60);
// how long a session is meant to last, which is what ends up in the session_expire column
// "0" is a meaningful value here - it is the constructor's own default and means "until the browser is closed" - so it
// has to be told apart from the variable not being set at all
$sessionLifetimeSetting = getenv('SESSION_LIFETIME');
$sessionLifetime = ($sessionLifetimeSetting === false || $sessionLifetimeSetting === '') ? 3600 : (int)$sessionLifetimeSetting;
$stopSession = (getenv('STOP_SESSION') == 'yes');
// base64 so that payloads with null bytes or anything else the environment cannot carry still get through intact
$writeDataBase64 = getenv('WRITE_DATA_BASE64');
// a payload of the given size, built here rather than passed in - the environment cannot carry hundreds of kilobytes
$writeBigData = (int)getenv('WRITE_BIG_DATA');
$readDataBase64 = (getenv('READ_DATA_BASE64') == 'yes');
// which $_SESSION key to write to and read from - two concurrent requests need to write different ones
$writeKey = getenv('WRITE_KEY') ?: $sid;
$readKey = getenv('READ_KEY') ?: $sid;
// report the session related ini settings the library promises to set
$getIni = (getenv('GET_INI') == 'yes');
// start a plain PHP session before the library is instantiated, to check that the library gets rid of it
$prestartSession = (getenv('PRESTART_SESSION') == 'yes');
// how many seconds the long running task holds the session for
$longTaskCycles = (int)(getenv('LONG_TASK_CYCLES') ?: 100);
$getSettings = (getenv('GET_SETTINGS') == 'yes');

// the garbage collection settings get_settings() reports on - a divisor of 0 used to make it fail
$gcProbability = getenv('GC_PROBABILITY');
$gcDivisor = getenv('GC_DIVISOR');
// the library used to set this one and does not anymore - see issue #37 - so the tests need to be able to give it a
// value of their own and check that it is still there once the constructor has run
$useStrictMode = getenv('USE_STRICT_MODE');

if ($gcProbability !== false && $gcProbability !== '') {
    ini_set('session.gc_probability', $gcProbability);
}

if ($gcDivisor !== false && $gcDivisor !== '') {
    ini_set('session.gc_divisor', $gcDivisor);
}

if ($useStrictMode !== false && $useStrictMode !== '') {
    ini_set('session.use_strict_mode', $useStrictMode);
}

// release the session lock behind the library's back, just before it closes the session, and then wait around long enough
// for the test to take that lock on a connection of its own. the library's own RELEASE_LOCK then runs against a lock held
// by somebody else, returns 0, and close() has to say so instead of carrying on as if all was well (issue #52)
$releaseLockEarly = (getenv('RELEASE_LOCK_EARLY') == 'yes');
$releaseLockEarlyPause = (int)(getenv('RELEASE_LOCK_EARLY_PAUSE') ?: 3);

// Everything below feeds the hash the library stores alongside the session and checks on every read - this is what ties
// a session to the visitor who started it. There is no web server here, so the values a browser would normally provide
// have to be put into $_SERVER by hand.
$userAgent = getenv('USER_AGENT');
$remoteAddr = getenv('REMOTE_ADDR');
$securityCode = getenv('SECURITY_CODE') ?: 'sec-code';
$lockToUserAgent = ((getenv('LOCK_TO_USER_AGENT') ?: 'yes') == 'yes');

// "no", "yes", or "callable:<value>" for the callable form of the argument, in which case the callable returns <value>
$lockToIp = getenv('LOCK_TO_IP') ?: 'no';

if (!empty($userAgent)) {
    $_SERVER['HTTP_USER_AGENT'] = $userAgent;
}

if (!empty($remoteAddr)) {
    $_SERVER['REMOTE_ADDR'] = $remoteAddr;
}

// the child is started with the environment phpunit itself was started with, and PHP copies environment variables into
// $_SERVER - so a REMOTE_ADDR set in the shell that launched the suite arrives here as a key that exists. Testing what
// happens when there is genuinely no REMOTE_ADDR means removing it here.
if (getenv('UNSET_REMOTE_ADDR') == 'yes') {
    unset($_SERVER['REMOTE_ADDR']);
}

// the library only sets session.cookie_secure when it thinks the connection is over HTTPS
if (getenv('HTTPS') == 'on') {
    $_SERVER['HTTPS'] = 'on';
}

if (strpos($lockToIp, 'callable:') === 0) {

    $lockToIpValue = substr($lockToIp, strlen('callable:'));
    $lockToIpArgument = function() use ($lockToIpValue) {
        return $lockToIpValue;
    };

} else {
    $lockToIpArgument = ($lockToIp == 'yes');
}

// session_regenerate_id() refuses to run once output has been sent, and everything this script prints counts as output.
// Buffering keeps that from happening; the buffer is flushed automatically when the script ends. Only done when actually
// regenerating, because the other scenarios rely on their output appearing while the process is still running.
if ($regenerateId || $stopSession) {
    ob_start();
}

// Establishing connection to the DB
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$dbname = getenv('DB_NAME') ?: 'test_db';
$user = getenv('DB_USER') ?: 'root';

// an empty password is a perfectly ordinary setting, and proc_open() drops environment entries whose value is an empty
// string - so an empty password arrives here as "not set at all". The suite always passes DB_PASS and its own default
// is empty, so absent has to mean empty rather than some password the server has never heard of.
$pass = getenv('DB_PASS');
$pass = $pass === false ? '' : $pass;
$table = getenv('DB_TABLE') ?: 'zebra_session_test_data';

// The library accepts either a PDO instance or a mysqli connection and has a separate code path for each, so the tests
// have to be able to run against both. "pdo" is the default because that is what the suite started out with.
$driver = getenv('DB_DRIVER') ?: 'pdo';

try {

    if ($driver === 'mysqli') {

        $link = new \mysqli($host, $user, $pass, $dbname, (int)$port);
        $link->set_charset('utf8mb4');

    } else {

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        $options = [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION];

        // the library is handed a connection the caller built, and callers do set this one - it makes every column come
        // back as a string, GET_LOCK and RELEASE_LOCK included
        if (getenv('PDO_STRINGIFY') == 'yes') {
            $options[\PDO::ATTR_STRINGIFY_FETCHES] = true;
        }

        $link = new \PDO($dsn, $user, $pass, $options);

    }

} catch (\Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit;
}

// The session id normally arrives in a cookie, and there is none in CLI, so it is set by hand.
// Which of the two ways below is used matters for anything that the constructor does with $_SESSION - flash data in
// particular, which the constructor can only pick up when it is the one starting the session.
if ($autostartSession) {

    // hand the id over the way a browser would and let the constructor start the session
    $_COOKIE[session_name()] = $sid;

}

$leftOverKey = 'left_over_from_the_previous_session';

/**
 * Starts PHP's own file based session and leaves a variable in it that must not survive the library taking over.
 *
 * In a function of its own rather than inline, because the library empties $_SESSION when it takes the session
 * over - which is the thing being checked further down - and static analysis reading a write and a read in one
 * scope concludes the read can never come up empty.
 *
 * @param   string  $key
 *
 * @return  void
 */
function prestartASession($key) {
    session_start();
    $_SESSION[$key] = 'this should not survive';
}

if ($prestartSession) {
    prestartASession($leftOverKey);
}

// Setting up the handler and starting the session.
// Anything the library throws is reported here rather than being left to PHP: whether an uncaught exception prints
// anything at all depends on display_errors and log_errors, which differ between machines - with both off there is no
// output whatsoever, only a non-zero exit code, and the tests would have nothing to look at.
try {

    $handler = new \Zebra_Session($link,
        $securityCode,
        $sessionLifetime,
        $lockToUserAgent,
        $lockToIpArgument,
        $lockTimeout,
        $table,
        $autostartSession,
        $readOnly
    );

    if (!$autostartSession) {
        session_id($sid);
        session_start();
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
    exit(1);
}
// Printed text will be analysed by the tests. Also handy for debugging.
echo json_encode(['session_start' => $sid]);
echo json_encode(['readonly' => ($readOnly ? 'yes' : 'no')]);

// If requested, report the number of active sessions.
// This runs before session_write_close(), so the row for *this* session does not exist yet and the printed number
// only reflects what the test seeded into the table.
// The value is printed exactly as the library returns it - no casting - so the tests also see its type.
if ($getActiveSessions) {
    echo json_encode(['active_sessions' => $handler->get_active_sessions()]);
}

// If requested, report what the library makes of the garbage collection settings.
if ($getSettings) {
    echo json_encode(['settings' => $handler->get_settings()]);
}

// If requested, report the session related ini settings.
if ($getIni) {
    echo json_encode(['ini' => [
        'session.cookie_httponly'   => ini_get('session.cookie_httponly'),
        'session.use_only_cookies'  => ini_get('session.use_only_cookies'),
        'session.cookie_lifetime'   => ini_get('session.cookie_lifetime'),
        'session.cookie_secure'     => ini_get('session.cookie_secure'),
        // the three the library deliberately stopped setting - reported so the tests can check they were left alone
        'session.use_strict_mode'   => ini_get('session.use_strict_mode'),
        'session.gc_probability'    => ini_get('session.gc_probability'),
        'session.gc_divisor'        => ini_get('session.gc_divisor'),
    ]]);
}

// If requested, report whether a session variable from before the library was instantiated is still around.
if ($prestartSession) {
    echo json_encode(['left_over' => isset($_SESSION[$leftOverKey]) ? $_SESSION[$leftOverKey] : null]);
}

// If requested, run the garbage collector on its own.
if ($runGc !== false && $runGc !== '') {
    $handler->gc((int)$runGc);
    echo json_encode(['gc' => (int)$runGc]);
}

// If requested, data is written to $_SESSION[$writeKey], which defaults to the session id
if (!empty($writeDataToSession)) {
    $_SESSION[$writeKey] = $writeDataToSession;
}

// The same, for payloads that cannot be passed through the environment as they are
if (!empty($writeDataBase64)) {
    $_SESSION[$writeKey] = base64_decode($writeDataBase64);
}

// A large payload, built from a repeating pattern the test can reproduce
if ($writeBigData > 0) {
    $_SESSION[$writeKey] = substr(str_repeat('abcdefghij', (int)ceil($writeBigData / 10)), 0, $writeBigData);
}

// If requested, set a flash data variable. Expected format is "name:value".
if (!empty($setFlashData)) {
    list($flashName, $flashValue) = explode(':', $setFlashData, 2);
    $handler->set_flashdata($flashName, $flashValue);
}

// If requested, print a flash data variable. Flash data is a plain session variable, so it is read as one.
// READ_FLASHDATA is a comma separated list, so that variables set in different requests can be compared side by side
if (!empty($readFlashData)) {

    $flashDataRead = [];

    foreach (explode(',', $readFlashData) as $flashDataName) {
        $flashDataRead[$flashDataName] = $_SESSION[$flashDataName] ?? null;
    }

    echo json_encode(['flashdata' => $flashDataRead]);

}

// If requested, the data is printed.
// Since the tests may not wait for the process to finish, we close and write the session before printing stored data.
// When the data is printed then it's guaranteed to be stored.
// Read it only when it was asked for, and coalesce the missing key - the value has to be captured before
// session_write_close() below, but scenarios that never write to the session would otherwise emit an "Undefined array key"
// warning straight into the stream the tests scan for their expected output.
$dataToPrint = $readData ? json_encode([$sid => $_SESSION[$readKey] ?? null]) : '';
$dataToPrintBase64 = $readDataBase64
    ? json_encode(['data_base64' => isset($_SESSION[$readKey]) ? base64_encode($_SESSION[$readKey]) : null])
    : '';

// Long-running task is supposed to lock the session.
if ($startLongTask) {
    $cycleCounter = $longTaskCycles;
    for ($i = 0; $i < $cycleCounter; $i++) {
        sleep(1);
        // Sleep until the counter runs out or the sessions table no longer exists (the unit test has ended but failed to kill the process)
        // both PDO and mysqli throw here when the table is gone, so the same call covers either driver
        try {
            $result = $link->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
        } catch (Exception $e) {
            // Table not found
            break;
        }
    }
}

// If requested, regenerate the session id. The new id is printed because the tests have to look up its row in the table.
// The row for the new id only appears once the session is closed, further below.
if ($regenerateId) {
    $handler->regenerate_id();
    echo json_encode(['new_session_id' => session_id()]);
}

// If requested, stop the session - unlike session_destroy() this also drops the session variables and the cookie.
if ($stopSession) {
    $handler->stop();
    echo json_encode(['stopped' => $sid]);
}

// If requested, destroy the session - this is what removes the row of the current session from the table.
if ($destroySession) {
    session_destroy();
    echo json_encode(['destroyed' => $sid]);
}

// Pull the lock out from under the library, on its own connection, so that the RELEASE_LOCK it runs on the way out finds
// nothing to release. Done here rather than earlier so that the session has definitely been read - and the lock therefore
// definitely taken - by the time it happens.
if ($releaseLockEarly) {
    $link->query('SELECT RELEASE_LOCK(\'session_' . sha1($sid) . '\')');
    echo json_encode(['lock_released_early' => $sid]);
    // the pause is what gives the test its window to grab the lock - without somebody else holding it, RELEASE_LOCK would
    // report that the lock does not exist rather than that it belongs to another connection
    sleep($releaseLockEarlyPause);
}

// Two cases must not close the session here:
// - flash data is moved into a temporary session variable by the library's shutdown function, which runs *after* this
//   point, so closing now would mean those changes never reach the database
// - after session_destroy() there is nothing left to write, and closing would only put the row back
if (!$setFlashData && !$readFlashData && !$destroySession && !$stopSession) {
    session_write_close();
}

if ($readData) {
    echo $dataToPrint;
}

if ($readDataBase64) {
    echo $dataToPrintBase64;
}
