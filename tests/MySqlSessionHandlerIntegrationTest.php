<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Tests run against a real MySQL instance - the handler depends on MySQL's GET_LOCK/RELEASE_LOCK, so there is no
 * in-memory substitute that would exercise the locking behaviour.
 * These are integration tests, exercising only public session API,
 * except for read-only session which is a Zebra specific feature.
 *
 * The tests usually spawn PHP processes instead of doing the testing directly.
 * There are two main reasons for this:
 * 1. It closely mimics a real world use case (concurrent requests).
 * 2. New instance of Zebra_Session automatically register itself as the handler.
 *    I didn't want to change that at this point, and keeping track of registered functions would require adding even more code.
 *
 * CAUTION: The tests rely on environmental variables, see phpunit.xml.dist for the full list.
 */
#[TestDox('MySQL session handler')]
class MySqlSessionHandlerIntegrationTest extends TestCase
{
    private static \PDO $pdo;
    /**
     * @var Process[] Keep track of all started processes to clean up in tearDown
     */
    private array $activeProcesses = [];
    private static ?string $testingSid = null;
    /**
     * @var string The driver the helper processes of the current test connect with - see driverProvider()
     */
    private string $driver = 'pdo';
    private static string $tableName = 'zebra_session_test_data';

    private static string $sessionTestHelperPath = __DIR__ . '/fixtures/sessionTestHelper.php';

    /**
     * Reports a setup problem - something that is wrong with the environment rather than with the library.
     *
     * The message is written to STDERR instead of being left to PHPUnit: this suite runs with testdox enabled, and the
     * testdox printer prints nothing at all for errors raised in setUpBeforeClass(), so both the reason and the settings
     * below would otherwise be invisible - the user would see a bare "Errors: 1".
     *
     * @param string $message
     * @return void
     */
    private static function reportSetupProblem(string $message): void
    {
        fwrite(STDERR, "\n" . $message . "\n" . self::requiredSettings() . "\n");
    }

    /**
     * The settings the suite needs, together with whatever is currently configured, so a misconfigured run says what is
     * missing instead of only what failed.
     */
    private static function requiredSettings(): string
    {
        $settings = [
            'RUN_DB_TESTS'      => 'must be true/1/yes/on to run the database tests',
            'DB_HOST'           => 'host of the MySQL instance',
            'DB_PORT'           => 'port of the MySQL instance',
            'DB_NAME'           => 'an existing database - tables are created in and dropped from it',
            'DB_USER'           => 'user with CREATE/DROP rights on that database',
            'DB_PASS'           => 'password for that user',
            'DB_TABLE'          => 'table used by the suite - must contain "test", it is dropped',
            'TEST_SESSION_ID'   => 'session id used by the spawned helper processes',
        ];

        $message = "Copy phpunit.xml.dist to phpunit.xml (git-ignored) and set the values below:\n";

        foreach ($settings as $name => $description) {
            $value = getenv($name);
            // distinguish "set to an empty string" (legitimate for DB_PASS) from "not set at all"
            $current = $value === false ? '<not set>' : ($value === '' ? '<empty>' : $value);
            // the value goes on its own line - column alignment falls apart as soon as a database or table name is long
            $message .= sprintf("  %s (currently: %s)\n      %s\n", $name, $current, $description);
        }

        return $message;
    }

    public static function setUpBeforeClass(): void
    {
        // accepts "true", "1", "yes", "on" - anything else, including an unset variable and the literal "false", disables
        // the suite. the previous check tested for an empty string only, so RUN_DB_TESTS=false switched the tests *on*.
        if (!filter_var(getenv('RUN_DB_TESTS'), FILTER_VALIDATE_BOOLEAN)) {
            self::reportSetupProblem('RUN_DB_TESTS is not enabled - the tests below need a real MySQL instance and were skipped.');
            static::markTestSkipped('RUN_DB_TESTS is not enabled - see phpunit.xml.dist');
        }

        self::$testingSid = getenv('TEST_SESSION_ID');
        self::$tableName = getenv('DB_TABLE') ?: self::$tableName;

        // the table is dropped both before and after the suite, so refuse to touch anything that is not clearly a test
        // table. without this a DB_TABLE pointing at a live "sessions" table would be destroyed by simply running phpunit.
        if (!str_contains(self::$tableName, 'test')) {
            self::reportSetupProblem('DB_TABLE must contain "test" - it is dropped by this suite. Got: ' . self::$tableName);
            static::fail('DB_TABLE must contain "test" - it is dropped by this suite. Got: ' . self::$tableName);
        }

        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT');
        $dbname = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        // a failed connection here means the settings are wrong, not that the library is broken - say so explicitly,
        // otherwise the only thing the user sees is a raw "SQLSTATE[HY000] [2002] Connection refused"
        try {
            self::$pdo = new \PDO($dsn, $user, $pass);
        } catch (\PDOException $e) {
            self::reportSetupProblem("Could not connect to MySQL at {$host}:{$port} as '{$user}': " . $e->getMessage());
            static::fail('Could not connect to MySQL - see the settings printed above.');
        }

        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Recreating table is important. The structure may change in the future.
        // Table should've been dropped during teardown, but something might've gone wrong.
        // The definition below is a copy of install/session_data.sql - keep the two in sync, otherwise the suite passes
        // against a schema no user of the library actually has.
        self::$pdo->exec('DROP TABLE IF EXISTS `' . self::$tableName . '`');
        self::$pdo->exec('CREATE TABLE `' . self::$tableName . '` (
                `session_id` varchar(32) NOT NULL default \'\',
                `hash` varchar(32) NOT NULL default \'\',
                `session_data` mediumblob NOT NULL,
                `session_expire` int(11) NOT NULL default \'0\',
                PRIMARY KEY (`session_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }

    /**
     * Test spawns PHP processes to verify session locking for concurrent requests.
     * A long-running process is started to lock the session.
     * Then we verify that a concurrent session cannot be opened, unless it's read-only.
     * Finally, we terminate the locking process and verify the session has been unlocked.
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A locked session blocks a second request, but still allows a read-only one ($_dataName)')]
    public function testSessionLock(string $driver): void
    {
        $this->driver = $driver;

        // First we start a long-running process to lock the session.
        $env = array_merge(getenv(), [
            'DB_DRIVER' => $driver,
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes'
        ]);

        $sessionLockProcess = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );

        $sessionLocked = $this->waitForOutput($sessionLockProcess, '{"session_start":"' . self::$testingSid . '"}');
        $this->assertTrue($sessionLocked, 'Unable to start a normal (locking) session. Timeout reached.');

        // The session is locked. We try to lock it again. It should time out.
        $env['START_LONG_TASK'] = 'no';
        $sessionHangProcess = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );
        $sessionHanged = $this->waitForOutput($sessionHangProcess, '{"session_start":"' . self::$testingSid . '"}', 2);
        $this->assertFalse($sessionHanged, 'Another process opened a locked session.');

        // this process is blocked inside GET_LOCK - it has to be stopped here, otherwise it would grab the lock the moment
        // the long-running process releases it, and the final step below would then fail for the wrong reason
        $sessionHangProcess->stop();

        // The session is still locked. We try to open a read-only session. It should normally start the session.
        $env['READ_ONLY'] = 'yes';
        $sessionROProcess = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );
        $sessionStarted = $this->waitForOutput($sessionROProcess, '{"session_start":"' . self::$testingSid . '"}');
        $this->assertTrue($sessionStarted, 'Unable to start read-only session. Timeout reached.');
        $sessionROProcess->stop();

        // Stopping the session locking process and running it again to verify the session has actually been released.
        // back to a normal session - a read-only one never takes a lock, so it would prove nothing here
        $sessionLockProcess->stop(0.1);
        $env['READ_ONLY'] = 'no';
        $sessionRelockProcess = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );

        $sessionLocked = $this->waitForOutput($sessionRelockProcess, '{"session_start":"' . self::$testingSid . '"}');
        $this->assertTrue($sessionLocked, "Unable to lock session after it's been closed. Timeout reached.");
        $sessionRelockProcess->stop();
    }

    /**
     * Test writing data to session:
     * - verify the data written in one request is available in another
     * - verify data written in read-only mode is not stored
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Data written in one request is visible in the next, and read-only writes are discarded ($_dataName)')]
    public function testSessionWrite(string $driver): void
    {
        $this->driver = $driver;

        // Open not read-only session and the data in another request.
        // Instead of closing session and opening it again, we keep it open and spawn a process to better reflect the real use case.
        $payloadNotToBeOverwritten = uniqid();
        $env = array_merge(getenv(), [
            'DB_DRIVER' => $driver,
            'READ_ONLY' => 'no',
            'WRITE_DATA_TO_SESSION' => $payloadNotToBeOverwritten,
            'READ_DATA_FROM_SESSION' => 'yes'
        ]);
        $writeToSession = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );
        $sessionLocked = $this->waitForOutput($writeToSession, '{"session_start":"' . self::$testingSid . '"}');
        $this->assertTrue($sessionLocked, 'Another process opened a locked session.');
        $writeToSession->stop();

        // Reopen the session and read the data.
        // We could do it directly, but a new Zebra_Session instance automatically registers itself as the handler, and it will mess up other tests.
        $env['READ_ONLY'] = 'no';
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        $readFromSession = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );
        $expectedOutput = json_encode([self::$testingSid => $payloadNotToBeOverwritten]);
        $payloadRead = $this->waitForOutput($readFromSession, $expectedOutput);
        $this->assertTrue($payloadRead, 'Saved value not read from session: '. $readFromSession->getOutput());
        $readFromSession->stop();

        // Now let's try to write data in RO session
        $payloadOverwriteTest = uniqid();
        $env['READ_ONLY'] = 'yes';
        $env['WRITE_DATA_TO_SESSION'] = $payloadOverwriteTest;
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        $writeToSession = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );
        // The script should output the new value, but it should not be saved
        $expectedOutput = json_encode([self::$testingSid => $payloadOverwriteTest]);
        $payloadRead = $this->waitForOutput($writeToSession, $expectedOutput);
        $this->assertTrue($payloadRead, 'Saved value not read from session: '. $writeToSession->getOutput());
        $writeToSession->stop();

        // Verify that session still holds the previous value
        $env['READ_ONLY'] = 'yes';
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        unset($env['WRITE_DATA_TO_SESSION']);
        $readFromSession = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );
        $expectedOutput = json_encode([self::$testingSid => $payloadNotToBeOverwritten]);
        $payloadRead = $this->waitForOutput($readFromSession, $expectedOutput);
        $this->assertTrue($payloadRead, "Value in session changed from '{$payloadNotToBeOverwritten}' to: " . $readFromSession->getOutput());
        $readFromSession->stop();
    }

    /**
     * get_active_sessions() has to count only the sessions that have not expired yet, and - since it runs the garbage
     * collector first - it also has to remove the expired ones from the table.
     * Both halves matter: the method was silently broken once (commit a20702a called gc() without its argument, which is
     * a fatal ArgumentCountError), so the helper process is also checked for a clean exit.
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('get_active_sessions() counts only unexpired sessions and garbage-collects the rest ($_dataName)')]
    public function testGetActiveSessions(string $driver): void
    {
        $this->driver = $driver;

        $this->seedSession('active-session-1', time() + 3600);
        $this->seedSession('active-session-2', time() + 3600);
        $this->seedSession('expired-session-1', time() - 10);
        $this->seedSession('expired-session-2', time() - 3600);

        // read-only, so the helper neither takes a lock nor writes a row of its own on shutdown - the count then depends
        // on the seeded rows alone
        $process = $this->runHelper([
            'READ_ONLY' => 'yes',
            'GET_ACTIVE_SESSIONS' => 'yes',
        ]);

        $output = $process->getOutput();
        $this->assertSame(
            1,
            preg_match('/\{"active_sessions":(.*?)\}/', $output, $matches),
            'get_active_sessions() produced no output. Got: ' . $output . $process->getErrorOutput()
        );

        // loose comparison - the value comes straight out of the database driver, which may hand back a string
        $this->assertEquals(2, json_decode($matches[1]), 'Wrong number of active sessions reported.');

        // the two expired rows have to be gone, the two active ones untouched
        $this->assertSame(['active-session-1', 'active-session-2'], $this->sessionIds(), 'Expired sessions were not garbage-collected.');
    }

    /**
     * gc() takes a $maxlifetime argument because SessionHandlerInterface requires one, but the library ignores it and
     * expires sessions by the session_expire column it wrote itself. Passing an absurd lifetime therefore has to change
     * nothing - if that ever stops being true, sessions would start outliving their stored expiration.
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('gc() expires sessions by their stored expiration and ignores the lifetime it is given ($_dataName)')]
    public function testGarbageCollectorIgnoresMaxlifetime(string $driver): void
    {
        $this->driver = $driver;

        $this->seedSession('active-session-1', time() + 3600);
        $this->seedSession('expired-session-1', time() - 10);

        // read-only, so the helper adds no row of its own
        $this->runHelper([
            'READ_ONLY' => 'yes',
            'RUN_GC' => '999999999',
        ]);

        $this->assertSame(['active-session-1'], $this->sessionIds(), 'gc() did not remove exactly the expired sessions.');
    }

    /**
     * Destroying a session has to remove its row - a session that outlives session_destroy() in the database is still a
     * valid session for anyone holding its id.
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Destroying a session removes its row from the table ($_dataName)')]
    public function testDestroySession(string $driver): void
    {
        $this->driver = $driver;

        // first request writes something, which is what creates the row
        $this->runHelper([
            'READ_ONLY' => 'no',
            'WRITE_DATA_TO_SESSION' => uniqid(),
        ]);
        $this->assertSame([self::$testingSid], $this->sessionIds(), 'The session row was not created in the first place.');

        // second request destroys the session
        $this->runHelper([
            'READ_ONLY' => 'no',
            'DESTROY_SESSION' => 'yes',
        ]);
        $this->assertSame([], $this->sessionIds(), 'The session row survived session_destroy().');
    }

    /**
     * regenerate_id() has to move the session to a new id: the old row must be gone (otherwise the point of regenerating
     * the id after a privilege change is lost, since the old id would still work) and the data must survive the move.
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Regenerating the id moves the session data to a new row and drops the old one ($_dataName)')]
    public function testRegenerateId(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();

        $this->runHelper([
            'READ_ONLY' => 'no',
            'WRITE_DATA_TO_SESSION' => $payload,
        ]);
        $this->assertSame([self::$testingSid], $this->sessionIds(), 'The session row was not created in the first place.');

        $process = $this->runHelper([
            'READ_ONLY' => 'no',
            'REGENERATE_ID' => 'yes',
        ]);

        $output = $process->getOutput();
        $this->assertSame(
            1,
            preg_match('/\{"new_session_id":"(.*?)"\}/', $output, $matches),
            'The helper did not report a new session id. Got: ' . $output
        );

        $newSessionId = $matches[1];
        $this->assertNotSame(self::$testingSid, $newSessionId, 'The session id did not change.');
        $this->assertSame([$newSessionId], $this->sessionIds(), 'The old session row was not removed.');

        // the data is checked straight in the blob - the helper stores it under the *old* id as its key, so it stays
        // reachable under that key no matter what the session is now called
        $statement = self::$pdo->prepare('SELECT session_data FROM `' . self::$tableName . '` WHERE session_id = ?');
        $statement->execute([$newSessionId]);
        $this->assertStringContainsString($payload, (string)$statement->fetchColumn(), 'Session data was lost when the id was regenerated.');
    }

    /**
     * Sessions are not always small - a shopping cart or a half filled in form adds up quickly - and the column they go
     * into used to be a `blob`, which stops at 65535 bytes. Going over that did not truncate the session quietly: the
     * write failed outright, so the request died and the visitor's session was lost.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session larger than 64KB survives being stored ($_dataName)')]
    public function testLargeSessionDataSurvives(string $driver): void
    {
        $this->driver = $driver;

        // comfortably past what a blob column can hold
        $size = 200000;
        $expected = substr(str_repeat('abcdefghij', (int)ceil($size / 10)), 0, $size);

        $this->runHelper(['READ_ONLY' => 'no', 'WRITE_BIG_DATA' => (string)$size]);

        $process = $this->runHelper(['READ_ONLY' => 'no', 'READ_DATA_BASE64' => 'yes']);
        $stored = $this->readSessionDataBase64($process);

        $this->assertNotNull($stored, 'A large session was not stored at all.');
        $this->assertSame(strlen($expected), strlen((string)$stored), 'A large session came back a different size than it went in.');
        $this->assertSame($expected, $stored, 'A large session came back altered.');
    }

    /**
     * Session data is arbitrary bytes - PHP's serialization of it, which can hold null bytes, quotes, backslashes and
     * anything a user typed. It goes into the database through a prepared statement and comes back out of a blob column,
     * and the two drivers are handed their character set in different ways, so both need checking.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Session data survives null bytes, quotes and multibyte characters ($_dataName)')]
    public function testAwkwardSessionDataSurvives(string $driver): void
    {
        $this->driver = $driver;

        $payload = "héllo—wörld 日本語 \x00 null byte, \"double\" and 'single' quotes, \\ backslash, \x01\x02\xff high bytes";

        $this->runHelper(['READ_ONLY' => 'no', 'WRITE_DATA_BASE64' => base64_encode($payload)]);

        $process = $this->runHelper(['READ_ONLY' => 'no', 'READ_DATA_BASE64' => 'yes']);
        $this->assertSame($payload, $this->readSessionDataBase64($process), 'Session data came back altered.');
    }

    /**
     * The class documents a handful of ini settings it takes care of on the visitor's behalf, which is the sort of
     * promise that quietly stops being true.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('The constructor sets the session ini options it promises to ($_dataName)')]
    public function testConstructorSetsItsIniOptions(string $driver): void
    {
        $this->driver = $driver;

        $process = $this->runHelper(['READ_ONLY' => 'yes', 'GET_INI' => 'yes', 'SESSION_LIFETIME' => '1234']);
        $ini = $this->readIni($process);

        $this->assertSame('1', $ini['session.cookie_httponly'] ?? null, 'The session cookie is exposed to client side scripting.');
        $this->assertSame('1', $ini['session.use_only_cookies'] ?? null, 'The session id may be passed around outside a cookie.');

        // note this is the lifetime as given, not the one clamped to session.gc_maxlifetime that the database sees
        $this->assertSame('1234', $ini['session.cookie_lifetime'] ?? null, 'The session cookie does not last as long as it was told to.');

        // no HTTPS here, so the secure flag has to stay off - setting it unconditionally would break plain HTTP sites
        $this->assertSame('0', $ini['session.cookie_secure'] ?? null, 'The session cookie was marked secure over a plain connection.');
    }

    /**
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('The session cookie is marked secure over HTTPS ($_dataName)')]
    public function testCookieIsMarkedSecureOverHttps(string $driver): void
    {
        $this->driver = $driver;

        $process = $this->runHelper(['READ_ONLY' => 'yes', 'GET_INI' => 'yes', 'HTTPS' => 'on']);
        $ini = $this->readIni($process);

        $this->assertSame('1', $ini['session.cookie_secure'] ?? null, 'The session cookie is not marked secure over HTTPS.');
    }

    /**
     * Flash data variables are counted one by one, so variables set in different requests have to expire in different
     * requests - a single shared counter would make the older one drag the newer one out with it.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Flash data variables set in different requests expire in different requests ($_dataName)')]
    public function testFlashDataVariablesExpireIndependently(string $driver): void
    {
        $this->driver = $driver;

        $first = uniqid();
        $second = uniqid();
        $env = ['READ_ONLY' => 'no', 'AUTOSTART_SESSION' => 'yes', 'READ_FLASHDATA' => 'first,second'];

        // request 1 sets the first variable
        $this->runHelper($env + ['SET_FLASHDATA' => 'first:' . $first]);

        // request 2 sets the second one; the first is on its last request
        $flash = $this->readFlashData($this->runHelper($env + ['SET_FLASHDATA' => 'second:' . $second]));
        $this->assertSame($first, $flash['first'] ?? null, 'The variable set in the previous request was already gone.');
        $this->assertSame($second, $flash['second'] ?? null, 'The variable just set was not readable.');

        // request 3: the first has expired, the second is on its last request
        $flash = $this->readFlashData($this->runHelper($env));
        $this->assertNull($flash['first'] ?? null, 'The older flash data variable outlived its request.');
        $this->assertSame($second, $flash['second'] ?? null, 'The newer flash data variable expired together with the older one.');

        // request 4: both gone
        $flash = $this->readFlashData($this->runHelper($env));
        $this->assertNull($flash['first'] ?? null, 'The older flash data variable is still around.');
        $this->assertNull($flash['second'] ?? null, 'The newer flash data variable outlived its request.');
    }

    /**
     * What the locking is ultimately for. Two requests writing different things to the same session must both survive -
     * without the lock the second one reads the session before the first one has written it, and then overwrites it,
     * losing the first one's work.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Two requests writing at the same time do not lose each other\'s data ($_dataName)')]
    public function testConcurrentWritesDoNotLoseData(string $driver): void
    {
        $this->driver = $driver;

        $slowPayload = uniqid();
        $fastPayload = uniqid();

        // a request that holds the session for a couple of seconds and only then writes
        $slow = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: array_merge(getenv(), [
                'DB_DRIVER' => $driver,
                'READ_ONLY' => 'no',
                'START_LONG_TASK' => 'yes',
                'LONG_TASK_CYCLES' => '2',
                'WRITE_KEY' => 'written_by_the_slow_request',
                'WRITE_DATA_TO_SESSION' => $slowPayload,
            ])
        );
        $this->assertTrue(
            $this->waitForOutput($slow, '{"session_start":"' . self::$testingSid . '"}'),
            'The slow request never started its session.'
        );

        // a second request for the same session, which has to wait its turn
        $fast = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: array_merge(getenv(), [
                'DB_DRIVER' => $driver,
                'READ_ONLY' => 'no',
                'WRITE_KEY' => 'written_by_the_fast_request',
                'WRITE_DATA_TO_SESSION' => $fastPayload,
            ])
        );

        $slow->wait();
        $fast->wait();

        $this->assertSame(0, $slow->getExitCode(), 'The slow request failed: ' . $slow->getErrorOutput() . $slow->getOutput());
        $this->assertSame(0, $fast->getExitCode(), 'The fast request failed: ' . $fast->getErrorOutput() . $fast->getOutput());

        $statement = self::$pdo->prepare('SELECT session_data FROM `' . self::$tableName . '` WHERE session_id = ?');
        $statement->execute([self::$testingSid]);
        $stored = (string)$statement->fetchColumn();

        $this->assertStringContainsString($slowPayload, $stored, 'The slow request\'s data was overwritten by the request that came after it.');
        $this->assertStringContainsString($fastPayload, $stored, 'The fast request\'s data never made it into the session.');
    }

    /**
     * Everything the library does needs a database connection, so being handed something else has to be refused right
     * away rather than failing somewhere deep in query().
     *
     * This one runs in the phpunit process rather than in a helper: the constructor throws before it registers itself as
     * the session handler, so there is nothing for it to leave behind.
     *
     * @return void
     */
    #[TestDox('The constructor refuses anything that is not a database connection')]
    public function testConstructorRejectsAnInvalidLink(): void
    {
        $link = new \stdClass();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Zebra_Session: No MySQL connection');

        new \Zebra_Session($link, 'sec-code');
    }

    /**
     * Being instantiated while a session is already running has to work - the class has always meant to handle it, and
     * plenty of code calls session_start() somewhere before reaching the line that sets the library up.
     *
     * It did not work: PHP refuses both ini_set() on session settings and session_set_save_handler() while a session is
     * active, and the constructor only got rid of the running session *after* making those calls. Every one of them
     * failed with a warning, and the application carried on using PHP's own file based handler without ever being told.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session that is already running is taken over cleanly ($_dataName)')]
    public function testAnAlreadyRunningSessionIsTakenOver(string $driver): void
    {
        $this->driver = $driver;

        $process = $this->runHelper(['READ_ONLY' => 'no', 'PRESTART_SESSION' => 'yes']);
        $output = $process->getOutput() . $process->getErrorOutput();

        // every one of the constructor's setup calls has to have been allowed to run
        $this->assertStringNotContainsString('Warning', $output, 'Setting the library up over a running session complained. Output: ' . $output);

        // nothing from the session that was running may be left over
        $this->assertStringContainsString('{"left_over":null}', $output, 'A variable from the previous session survived.');

        // and the library really did take over - the session went into the table rather than into a file
        $this->assertSame([self::$testingSid], $this->sessionIds(), 'The session was not stored by the library.');
    }

    /**
     * A read-only request has to survive running alongside a normal one on the same session - that is the entire reason
     * read-only sessions exist.
     *
     * It did not: read-only sessions never take a lock, but close() used to ask MySQL to release one anyway, and
     * RELEASE_LOCK returns 0 when the named lock is held by another connection. The result was a fatal error at the very
     * end of the request, and only ever when a second request happened to be holding the session at the same time.
     *
     * The existing locking test never caught it because it kills the read-only process instead of letting it finish.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A read-only request finishes cleanly while another request holds the session ($_dataName)')]
    public function testReadOnlyRequestFinishesCleanlyWhileTheSessionIsLocked(string $driver): void
    {
        $this->driver = $driver;

        // hold the session for the duration of this test
        $lockProcess = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: array_merge(getenv(), [
                'DB_DRIVER' => $driver,
                'READ_ONLY' => 'no',
                'START_LONG_TASK' => 'yes',
            ])
        );
        $this->assertTrue(
            $this->waitForOutput($lockProcess, '{"session_start":"' . self::$testingSid . '"}'),
            'Unable to start the session that holds the lock. Timeout reached.'
        );

        // a read-only request, this time allowed to run all the way through its shutdown
        $process = $this->runHelper(['READ_ONLY' => 'yes', 'READ_DATA_FROM_SESSION' => 'yes']);

        $this->assertStringNotContainsString(
            'Could not release session lock',
            $process->getOutput() . $process->getErrorOutput(),
            'A read-only request tried to release a lock it never took.'
        );

        $lockProcess->stop();
    }

    /**
     * The flip side of the above: a read-only session must not take the lock at all, otherwise it would block the very
     * requests it is meant to run alongside. Asked straight of MySQL rather than inferred from timing.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A read-only session takes no lock, a normal one does ($_dataName)')]
    public function testReadOnlySessionTakesNoLock(string $driver): void
    {
        $this->driver = $driver;

        // the name the library derives from the session id - see read()
        $lockName = 'session_' . sha1((string)self::$testingSid);

        foreach (['yes' => false, 'no' => true] as $readOnly => $lockExpected) {

            $process = $this->startBackgroundProcess(
                command: [
                    PHP_BINARY,
                    self::$sessionTestHelperPath,
                ],
                env: array_merge(getenv(), [
                    'DB_DRIVER' => $driver,
                    'READ_ONLY' => $readOnly,
                    'START_LONG_TASK' => 'yes',
                ])
            );
            $this->assertTrue(
                $this->waitForOutput($process, '{"readonly":"' . $readOnly . '"}'),
                'Unable to start the session. Timeout reached.'
            );

            // IS_USED_LOCK returns the id of the connection holding the lock, or NULL when nobody holds it
            $statement = self::$pdo->prepare('SELECT IS_USED_LOCK(?)');
            $statement->execute([$lockName]);
            $holder = $statement->fetchColumn();

            $this->assertSame(
                $lockExpected,
                $holder !== null && $holder !== false,
                $readOnly === 'yes'
                    ? 'A read-only session took the session lock, which would block the requests it is meant to run alongside.'
                    : 'A normal session did not take the session lock.'
            );

            $process->stop();
        }
    }

    /**
     * The table name is wrapped in backticks by the constructor, so passing one that is already wrapped - which is what
     * anyone copying the name out of a database client ends up doing - has to work just the same.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A table name that already comes wrapped in backticks works ($_dataName)')]
    public function testTableNameMayComeWrappedInBackticks(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();
        $env = ['READ_ONLY' => 'no', 'DB_TABLE' => '`' . self::$tableName . '`'];

        $this->runHelper($env + ['WRITE_DATA_TO_SESSION' => $payload]);

        $process = $this->runHelper($env + ['READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame($payload, $this->readSessionData($process), 'The session was not stored in the table the backticked name refers to.');

        // and it went into the table the test knows about, not one that got created along the way
        $this->assertSame([self::$testingSid], $this->sessionIds());
    }

    /**
     * Expiration is enforced on the way in, not only by the garbage collector: read() ignores rows whose session_expire
     * has passed. Without that, an expired session would stay usable for as long as nothing happened to trigger garbage
     * collection - which, with the default probability, can be a long time.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('An expired session is not readable even while its row is still in the table ($_dataName)')]
    public function testExpiredSessionIsNotReadable(string $driver): void
    {
        $this->driver = $driver;

        $userAgent = 'Mozilla/5.0 (expiry test)';
        $payload = uniqid();

        // what the handler will rebuild and compare against: user agent + security code, with lock_to_ip left off
        $hash = md5($userAgent . 'sec-code');
        $data = self::$testingSid . '|' . serialize($payload);
        $env = ['USER_AGENT' => $userAgent, 'READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes'];

        // a row that has not expired reads back - this is what makes the second half meaningful
        $this->seedSession((string)self::$testingSid, time() + 3600, $hash, $data);
        $process = $this->runHelper($env);
        $this->assertSame($payload, $this->readSessionData($process), 'A seeded session that is still valid could not be read.');

        // the very same row, expired
        $this->clearSessions();
        $this->seedSession((string)self::$testingSid, time() - 10, $hash, $data);
        $process = $this->runHelper($env);
        $this->assertNull($this->readSessionData($process), 'An expired session was still readable.');
    }

    /**
     * The session lifetime given to the constructor is what read() later measures against, so it has to end up in the
     * session_expire column - but never below session.gc_maxlifetime, since the library takes the larger of the two.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('The session lifetime decides when a session expires ($_dataName)')]
    public function testSessionLifetimeDecidesWhenASessionExpires(string $driver): void
    {
        $this->driver = $driver;

        // phpunit and the helper read the same php.ini, so this is the value the library will be comparing against
        $gcMaxlifetime = (int)ini_get('session.gc_maxlifetime');

        // comfortably longer than gc_maxlifetime, so the lifetime is what wins
        $lifetime = $gcMaxlifetime + 3600;

        $before = time();
        $this->runHelper(['READ_ONLY' => 'no', 'SESSION_LIFETIME' => (string)$lifetime, 'WRITE_DATA_TO_SESSION' => uniqid()]);
        $after = time();

        $expire = $this->sessionExpire((string)self::$testingSid);
        $this->assertGreaterThanOrEqual($before + $lifetime, $expire, 'The session expires earlier than the lifetime it was given.');
        $this->assertLessThanOrEqual($after + $lifetime, $expire, 'The session expires later than the lifetime it was given.');

        // a lifetime shorter than gc_maxlifetime must not shorten the session - PHP would collect it either way
        $this->clearSessions();

        $before = time();
        $this->runHelper(['READ_ONLY' => 'no', 'SESSION_LIFETIME' => '1', 'WRITE_DATA_TO_SESSION' => uniqid()]);
        $after = time();

        $expire = $this->sessionExpire((string)self::$testingSid);
        $this->assertGreaterThanOrEqual($before + $gcMaxlifetime, $expire, 'A short lifetime was not raised to session.gc_maxlifetime.');
        $this->assertLessThanOrEqual($after + $gcMaxlifetime, $expire, 'A short lifetime ended up longer than session.gc_maxlifetime.');
    }

    /**
     * A lifetime of 0 is the constructor's own default, and it means something different from every other value: the
     * cookie lasts until the browser is closed, and how long the session itself survives is left to
     * session.gc_maxlifetime. Every other test here passes an explicit lifetime, so this is the configuration most
     * people actually run and the one nothing else covers.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('The default lifetime of 0 means a cookie until the browser closes, and gc_maxlifetime in the database ($_dataName)')]
    public function testDefaultSessionLifetime(string $driver): void
    {
        $this->driver = $driver;

        // phpunit and the helper read the same php.ini
        $gcMaxlifetime = (int)ini_get('session.gc_maxlifetime');

        $before = time();
        $process = $this->runHelper([
            'READ_ONLY' => 'no',
            'SESSION_LIFETIME' => '0',
            'GET_INI' => 'yes',
            'WRITE_DATA_TO_SESSION' => uniqid(),
        ]);
        $after = time();

        $ini = $this->readIni($process);
        $this->assertSame('0', $ini['session.cookie_lifetime'] ?? null, 'The session cookie was given a lifetime instead of lasting until the browser closes.');

        // the stored expiration still has to be a real moment in the future, taken from gc_maxlifetime
        $expire = $this->sessionExpire((string)self::$testingSid);
        $this->assertGreaterThanOrEqual($before + $gcMaxlifetime, $expire, 'The session expires sooner than session.gc_maxlifetime.');
        $this->assertLessThanOrEqual($after + $gcMaxlifetime, $expire, 'The session expires later than session.gc_maxlifetime.');
    }

    /**
     * stop() is the "log out" call - it has to leave nothing behind for the session id to be worth anything afterwards.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('stop() leaves nothing of the session behind ($_dataName)')]
    public function testStopRemovesTheSession(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();

        $this->runHelper(['READ_ONLY' => 'no', 'WRITE_DATA_TO_SESSION' => $payload]);
        $this->assertSame([self::$testingSid], $this->sessionIds(), 'The session row was not created in the first place.');

        $this->runHelper(['READ_ONLY' => 'no', 'STOP_SESSION' => 'yes']);
        $this->assertSame([], $this->sessionIds(), 'The session row survived stop().');

        // and the data is really gone, not just the row it happened to be in
        $process = $this->runHelper(['READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertNull($this->readSessionData($process), 'The session data was still there after stop().');
    }

    /**
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('get_settings() reports the garbage collection settings ($_dataName)')]
    public function testGetSettingsReportsTheGarbageCollectionSettings(string $driver): void
    {
        $this->driver = $driver;

        $process = $this->runHelper([
            'READ_ONLY' => 'yes',
            'GET_SETTINGS' => 'yes',
            'GC_PROBABILITY' => '1',
            'GC_DIVISOR' => '100',
        ]);

        $settings = $this->readSettings($process);

        $this->assertSame('1', $settings['session.gc_probability'] ?? null);
        $this->assertSame('100', $settings['session.gc_divisor'] ?? null);
        $this->assertSame('1%', $settings['probability'] ?? null, 'The chance of the garbage collector running was computed wrong.');
        $this->assertArrayHasKey('session.gc_maxlifetime', $settings);
        $this->assertArrayHasKey('session.use_strict_mode', $settings);
    }

    /**
     * A divisor of 0 means "never collect garbage". It used to make get_settings() divide by zero, which is a fatal
     * error in PHP 8 - hence the check on the helper's exit code inside runHelper().
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('get_settings() copes with a garbage collection divisor of zero ($_dataName)')]
    public function testGetSettingsWithAZeroDivisor(string $driver): void
    {
        $this->driver = $driver;

        $process = $this->runHelper([
            'READ_ONLY' => 'yes',
            'GET_SETTINGS' => 'yes',
            'GC_PROBABILITY' => '1',
            'GC_DIVISOR' => '0',
        ]);

        $settings = $this->readSettings($process);

        $this->assertSame('0%', $settings['probability'] ?? null, 'A divisor of 0 should mean the garbage collector never runs.');
    }

    /**
     * The library stores a hash of "who started this session" next to the session itself and rebuilds it on every read.
     * If the two do not match the session is thrown away. That is the whole point of the library over PHP's own handler,
     * so each ingredient of that hash gets the same treatment here.
     *
     * The last step matters as much as the middle one: a session that is merely hidden from the impostor while remaining
     * usable by the original visitor would still be a hijackable session.
     *
     * @param array<string, string> $identity Environment describing the visitor that starts the session
     * @param array<string, string> $changedIdentity The same, with one ingredient of the hash changed
     * @param string $what The changed ingredient, for assertion messages
     * @return void
     */
    protected function assertSessionIsInvalidatedBy(array $identity, array $changedIdentity, string $what): void
    {
        $payload = uniqid();

        // the original visitor stores something
        $this->runHelper($identity + ['READ_ONLY' => 'no', 'WRITE_DATA_TO_SESSION' => $payload]);

        // ...and gets it back on the next request - without this the rest of the test would pass even if the session
        // never worked in the first place
        $process = $this->runHelper($identity + ['READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame($payload, $this->readSessionData($process), 'The session could not be read back with an unchanged ' . $what . '.');

        // a request with a different identity must not see it
        $process = $this->runHelper($changedIdentity + ['READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertNull($this->readSessionData($process), 'A changed ' . $what . ' was handed the session data.');

        // and the data is gone for good, not just hidden from the impostor
        $process = $this->runHelper($identity + ['READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertNull($this->readSessionData($process), 'The session survived being read with a changed ' . $what . '.');
    }

    /**
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session is invalidated when the user agent changes ($_dataName)')]
    public function testSessionIsInvalidatedWhenTheUserAgentChanges(string $driver): void
    {
        $this->driver = $driver;

        $this->assertSessionIsInvalidatedBy(
            ['USER_AGENT' => 'Mozilla/5.0 (the visitor who started the session)'],
            ['USER_AGENT' => 'Mozilla/5.0 (somebody else entirely)'],
            'user agent'
        );
    }

    /**
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session is invalidated when the security code changes ($_dataName)')]
    public function testSessionIsInvalidatedWhenTheSecurityCodeChanges(string $driver): void
    {
        $this->driver = $driver;

        $this->assertSessionIsInvalidatedBy(
            ['SECURITY_CODE' => 'sEcUr1tY_c0dE'],
            ['SECURITY_CODE' => 'a different security code'],
            'security code'
        );
    }

    /**
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session is invalidated when the IP address changes and lock_to_ip is on ($_dataName)')]
    public function testSessionIsInvalidatedWhenTheIpChanges(string $driver): void
    {
        $this->driver = $driver;

        $this->assertSessionIsInvalidatedBy(
            ['LOCK_TO_IP' => 'yes', 'REMOTE_ADDR' => '198.51.100.1'],
            ['LOCK_TO_IP' => 'yes', 'REMOTE_ADDR' => '198.51.100.2'],
            'IP address'
        );
    }

    /**
     * lock_to_ip can also be a callable, which is what makes the library usable behind a load balancer or a proxy: the
     * value that identifies the visitor is then whatever the callable returns rather than REMOTE_ADDR.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session is invalidated when the value returned by the lock_to_ip callable changes ($_dataName)')]
    public function testSessionIsInvalidatedWhenTheLockToIpCallableReturnsSomethingElse(string $driver): void
    {
        $this->driver = $driver;

        $this->assertSessionIsInvalidatedBy(
            ['LOCK_TO_IP' => 'callable:198.51.100.1'],
            ['LOCK_TO_IP' => 'callable:198.51.100.2'],
            'value returned by the lock_to_ip callable'
        );
    }

    /**
     * Locking to the user agent is on by default, but turning it off has to actually turn it off - otherwise the option
     * would be doing nothing and nobody would notice, since the default behaviour would still look correct.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Turning off lock_to_user_agent lets a session survive a different user agent ($_dataName)')]
    public function testLockToUserAgentCanBeTurnedOff(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();
        $env = ['READ_ONLY' => 'no', 'LOCK_TO_USER_AGENT' => 'no'];

        $this->runHelper($env + ['USER_AGENT' => 'Mozilla/5.0 (the browser that started it)', 'WRITE_DATA_TO_SESSION' => $payload]);

        $process = $this->runHelper($env + ['USER_AGENT' => 'Mozilla/5.0 (a completely different browser)', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame(
            $payload,
            $this->readSessionData($process),
            'The session was thrown away over a changed user agent even though lock_to_user_agent was off.'
        );
    }

    /**
     * REMOTE_ADDR is not always there - command line scripts have none, and neither do some SAPI configurations. Reading
     * it blindly raised a warning, and since the warning counts as output it also stopped PHP from sending the session
     * cookie for that request.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Locking to the IP address copes with REMOTE_ADDR not being set ($_dataName)')]
    public function testLockToIpCopesWithoutARemoteAddress(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();

        // the helper removes $_SERVER['REMOTE_ADDR'] outright - passing an empty one through the environment would not do,
        // since PHP copies environment variables into $_SERVER and the key would still be there
        $env = ['READ_ONLY' => 'no', 'LOCK_TO_IP' => 'yes', 'UNSET_REMOTE_ADDR' => 'yes'];

        $process = $this->runHelper($env + ['WRITE_DATA_TO_SESSION' => $payload]);
        $this->assertStringNotContainsString(
            'REMOTE_ADDR',
            $process->getOutput() . $process->getErrorOutput(),
            'Locking to the IP address complained about REMOTE_ADDR not being set.'
        );

        // and the session still works - the missing address just contributes nothing to the hash
        $process = $this->runHelper($env + ['READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame($payload, $this->readSessionData($process), 'The session was unusable without a REMOTE_ADDR.');
    }

    /**
     * The other half of the callable form, and the reason it exists: when the callable decides what identifies the
     * visitor, REMOTE_ADDR changing between requests - which is exactly what happens behind a load balancer - must not
     * cost the visitor their session.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A lock_to_ip callable keeps the session alive across a changing REMOTE_ADDR ($_dataName)')]
    public function testLockToIpCallableSurvivesAChangingRemoteAddress(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();
        $identity = ['LOCK_TO_IP' => 'callable:198.51.100.1', 'READ_ONLY' => 'no'];

        $this->runHelper($identity + ['REMOTE_ADDR' => '203.0.113.1', 'WRITE_DATA_TO_SESSION' => $payload]);

        // same visitor as far as the callable is concerned, different address as far as the server is concerned
        $process = $this->runHelper($identity + ['REMOTE_ADDR' => '203.0.113.2', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame(
            $payload,
            $this->readSessionData($process),
            'The session was lost when REMOTE_ADDR changed, even though the lock_to_ip callable returned the same value.'
        );
    }

    /**
     * Giving up on the lock has to be loud. If GET_LOCK times out and the library carries on anyway, the request runs
     * with no lock at all - which is exactly the situation locking exists to prevent - and nothing would say so.
     *
     * The check for this in read() compares the value MySQL returned against the integer 0, so it only works as long as
     * both drivers really hand back an integer there.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A request that cannot obtain the session lock fails loudly ($_dataName)')]
    public function testLockTimeoutIsReported(string $driver): void
    {
        $this->driver = $driver;

        // hold the lock for as long as this test needs it
        $lockProcess = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: array_merge(getenv(), [
                'DB_DRIVER' => $driver,
                'READ_ONLY' => 'no',
                'START_LONG_TASK' => 'yes',
            ])
        );
        $this->assertTrue(
            $this->waitForOutput($lockProcess, '{"session_start":"' . self::$testingSid . '"}'),
            'Unable to start the session that holds the lock. Timeout reached.'
        );

        // a second request which gives up after a second instead of the usual minute
        $process = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: array_merge(getenv(), [
                'DB_DRIVER' => $driver,
                'READ_ONLY' => 'no',
                'LOCK_TIMEOUT' => '1',
            ])
        );
        $process->wait();

        // display_errors sends the uncaught exception to stdout in CLI, so both streams are searched
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), 'The request carried on without ever getting the lock. Output: ' . $output);
        $this->assertStringContainsString('Could not obtain session lock', $output, 'The lock timeout was not reported. Output: ' . $output);

        $lockProcess->stop();
    }

    /**
     * Both ways of starting the session have to behave the same as far as flash data is concerned.
     *
     * They did not: when the caller starts the session itself, the class is instantiated before there is a session to
     * read, so flash data used to go unnoticed on every subsequent request and the variables were never deleted.
     *
     * @return array<string, array<string>>
     */
    public static function sessionStartProvider(): array
    {
        $cases = [];

        foreach (self::driverProvider() as $driverName => $driver) {
            $cases[$driverName . ', session started by the library'] = [$driver[0], 'yes'];
            $cases[$driverName . ', session started by the caller'] = [$driver[0], 'no'];
        }

        return $cases;
    }

    /**
     * Flash data has to survive exactly one further request: it is readable in the request that set it and in the next
     * one, and is gone in the one after that.
     *
     * @param string $driver The driver the helper connects with
     * @param string $autostart Whether the library starts the session ("yes") or the caller does ("no")
     * @return void
     */
    #[DataProvider('sessionStartProvider')]
    #[TestDox('Flash data survives exactly one further request ($_dataName)')]
    public function testFlashData(string $driver, string $autostart): void
    {
        $this->driver = $driver;

        $payload = uniqid();

        $env = [
            'READ_ONLY' => 'no',
            'AUTOSTART_SESSION' => $autostart,
            'READ_FLASHDATA' => 'flashvar',
        ];

        // the request that sets it can also read it
        $process = $this->runHelper($env + ['SET_FLASHDATA' => 'flashvar:' . $payload]);
        $this->assertSame($payload, $this->readFlashData($process)['flashvar'] ?? null, 'Flash data was not readable in the request that set it.');

        // the next request still sees it
        $process = $this->runHelper($env);
        $this->assertSame($payload, $this->readFlashData($process)['flashvar'] ?? null, 'Flash data was not available in the next request.');

        // and the one after that does not
        $process = $this->runHelper($env);
        $this->assertNull($this->readFlashData($process)['flashvar'] ?? null, 'Flash data outlived the request it was supposed to be deleted in.');
    }

    public static function tearDownAfterClass(): void
    {
        // $pdo is a typed static with no default, so it stays uninitialised when setUpBeforeClass skipped or failed early -
        // touching it in that state raises "typed static property must not be accessed before initialization"
        if (!isset(self::$pdo)) {
            return;
        }

        self::$pdo->exec('DROP TABLE IF EXISTS `' . self::$tableName . '`');
    }

    /**
     * Clean up any processes that were left running during a test.
     */
    protected function tearDown(): void
    {
        foreach ($this->activeProcesses as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        // Clear the array for the next test method
        $this->activeProcesses = [];

        parent::tearDown();
    }

    protected function setUp(): void
    {
        if (!filter_var(getenv('RUN_DB_TESTS'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('RUN_DB_TESTS is not enabled - see phpunit.xml.dist');
        }
        self::$pdo->exec('TRUNCATE TABLE `' . self::$tableName . '`');
    }

    /**
     * The library talks to MySQL either through PDO or through mysqli, and query() has a separate implementation for
     * each - different parameter binding, different way of counting rows, different error handling. Everything the suite
     * checks therefore has to be checked against both.
     *
     * @return array<string, array<string>>
     */
    public static function driverProvider(): array
    {
        return [
            'PDO' => ['pdo'],
            'mysqli' => ['mysqli'],
        ];
    }

    /**
     * Runs the helper to completion and asserts it did not die on the way - a fatal error there would otherwise show up
     * as a confusing assertion failure further down.
     *
     * @param array<string, string> $extraEnv Added on top of the environment phpunit was started with
     * @return Process
     */
    protected function runHelper(array $extraEnv): Process
    {
        $process = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: array_merge(getenv(), ['DB_DRIVER' => $this->driver], $extraEnv)
        );
        $process->wait();

        $this->assertSame(
            0,
            $process->getExitCode(),
            'Helper process failed: ' . $process->getErrorOutput() . $process->getOutput()
        );

        return $process;
    }

    /**
     * The session ids currently in the table, sorted, so assertions can compare against a plain array.
     *
     * @return array<string>
     */
    protected function sessionIds(): array
    {
        $ids = self::$pdo->query('SELECT session_id FROM `' . self::$tableName . '` ORDER BY session_id')->fetchAll(\PDO::FETCH_COLUMN);

        return array_map('strval', $ids);
    }

    /**
     * Pulls the session value out of the helper's output - the helper prints it keyed by the session id.
     *
     * @param Process $process
     * @return string|null Null when the session held nothing, which is a result the tests assert on
     */
    protected function readSessionData(Process $process): ?string
    {
        $output = $process->getOutput();

        $this->assertSame(
            1,
            preg_match('/\{"' . preg_quote((string)self::$testingSid, '/') . '":(.*?)\}/', $output, $matches),
            'The helper printed no session data at all. Got: ' . $output
        );

        $value = json_decode($matches[1]);

        return $value === null ? null : (string)$value;
    }

    /**
     * Pulls the flash data value out of the helper's output.
     *
     * @param Process $process
     * @return array<string, string|null> The requested variables, null where the variable was not set
     */
    protected function readFlashData(Process $process): array
    {
        $output = $process->getOutput();

        // a flat object, so matching up to the first closing brace is enough
        $this->assertSame(
            1,
            preg_match('/"flashdata":(\{[^{}]*\})/', $output, $matches),
            'The helper printed no flash data at all. Got: ' . $output
        );

        return (array)json_decode($matches[1], true);
    }

    /**
     * Pulls a base64 encoded session value out of the helper's output, for payloads that do not survive being compared
     * as JSON - anything with null bytes or invalid UTF-8 in it.
     *
     * @param Process $process
     * @return string|null
     */
    protected function readSessionDataBase64(Process $process): ?string
    {
        $output = $process->getOutput();

        $this->assertSame(
            1,
            preg_match('/"data_base64":(".*?"|null)/', $output, $matches),
            'The helper printed no session data at all. Got: ' . $output
        );

        $value = json_decode($matches[1]);

        return $value === null ? null : base64_decode((string)$value);
    }

    /**
     * Pulls the reported ini settings out of the helper's output.
     *
     * @param Process $process
     * @return array<string, string>
     */
    protected function readIni(Process $process): array
    {
        $output = $process->getOutput();

        $this->assertSame(
            1,
            preg_match('/"ini":(\{[^{}]*\})/', $output, $matches),
            'The helper printed no ini settings at all. Got: ' . $output
        );

        return (array)json_decode($matches[1], true);
    }

    /**
     * Writes a session row straight into the table, bypassing the handler - the point is to control session_expire,
     * which the handler always derives from time() plus the session lifetime.
     *
     * @param string $sessionId
     * @param int $expire Unix timestamp at which the session expires
     * @param string|null $hash The hash the handler will be comparing against; defaults to one no visitor can produce
     * @param string $data Session data in PHP's session serialization format
     * @return void
     */
    protected function seedSession(string $sessionId, int $expire, ?string $hash = null, string $data = ''): void
    {
        $statement = self::$pdo->prepare(
            'INSERT INTO `' . self::$tableName . '` (session_id, hash, session_data, session_expire) VALUES (?, ?, ?, ?)'
        );
        $statement->execute([$sessionId, $hash ?? md5($sessionId), $data, $expire]);
    }

    /**
     * Empties the table mid-test, for tests that need to seed a second time.
     *
     * @return void
     */
    protected function clearSessions(): void
    {
        self::$pdo->exec('TRUNCATE TABLE `' . self::$tableName . '`');
    }

    /**
     * The moment a stored session is set to expire.
     *
     * @param string $sessionId
     * @return int
     */
    protected function sessionExpire(string $sessionId): int
    {
        $statement = self::$pdo->prepare('SELECT session_expire FROM `' . self::$tableName . '` WHERE session_id = ?');
        $statement->execute([$sessionId]);

        return (int)$statement->fetchColumn();
    }

    /**
     * Pulls what get_settings() returned out of the helper's output.
     *
     * @param Process $process
     * @return array<string, string>
     */
    protected function readSettings(Process $process): array
    {
        $output = $process->getOutput();

        // the settings are a flat array, so matching up to the first closing brace is enough
        $this->assertSame(
            1,
            preg_match('/"settings":(\{[^{}]*\})/', $output, $matches),
            'The helper printed no settings at all. Got: ' . $output
        );

        return (array)json_decode($matches[1], true);
    }

    /**
     * Helper to start a background process.
     *
     * @param array<string> $command The command to run as an array of arguments
     * @param string|null $cwd The working directory
     * @param array<string>|null $env Environment variables
     * @return Process
     */
    protected function startBackgroundProcess(array $command, ?string $cwd = null, ?array $env = null): Process
    {
        $process = new Process($command, $cwd, $env);

        // Disable blocking / run in background
        $process->start();

        // Track the process for tearDown cleanup
        $this->activeProcesses[] = $process;

        return $process;
    }

    /**
     * Wait for a specific string to appear in the process output.
     * @param Process $process
     * @param string $needle
     * @param float $timeoutInSeconds
     * @return bool
     */
    protected function waitForOutput(Process $process, string $needle, float $timeoutInSeconds = 5): bool
    {
        // microtime() rather than time() - time() only advances on whole-second boundaries, so a one second timeout could
        // expire after a couple of milliseconds depending on when in the current second the call happened to be made, and
        // every positive assertion here needs a PHP process to spawn, connect to the database and start a session first
        $start = microtime(true);
        while (microtime(true) - $start < $timeoutInSeconds) {
            if (str_contains($process->getOutput(), $needle)) {
                return true;
            }
            usleep(50000); // Sleep for 50ms to prevent CPU pegging
        }
        return false;
    }
}
