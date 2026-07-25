<?php

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
    private static string $tableName = 'zebra_session_test_data';

    private static string $sessionTestHelperPath = __DIR__ . '/fixtures/sessionTestHelper.php';

    public static function setUpBeforeClass(): void
    {
        // accepts "true", "1", "yes", "on" - anything else, including an unset variable and the literal "false", disables
        // the suite. the previous check tested for an empty string only, so RUN_DB_TESTS=false switched the tests *on*.
        if (!filter_var(getenv('RUN_DB_TESTS'), FILTER_VALIDATE_BOOLEAN)) {
            static::markTestSkipped('RUN_DB_TESTS is not enabled - see phpunit.xml.dist');
        }

        self::$testingSid = getenv('TEST_SESSION_ID');
        self::$tableName = getenv('DB_TABLE') ?: self::$tableName;

        // the table is dropped both before and after the suite, so refuse to touch anything that is not clearly a test
        // table. without this a DB_TABLE pointing at a live "sessions" table would be destroyed by simply running phpunit.
        if (!str_contains(self::$tableName, 'test')) {
            static::fail('DB_TABLE must contain "test" - it is dropped by this suite. Got: ' . self::$tableName);
        }

        $host = getenv('DB_HOST');
        $port = getenv('DB_PORT');
        $dbname = getenv('DB_NAME');
        $user = getenv('DB_USER');
        $pass = getenv('DB_PASS');

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
        self::$pdo = new \PDO($dsn, $user, $pass);
        self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Recreating table is important. The structure may change in the future.
        // Table should've been dropped during teardown, but something might've gone wrong.
        // The definition below is a copy of install/session_data.sql - keep the two in sync, otherwise the suite passes
        // against a schema no user of the library actually has.
        self::$pdo->exec('DROP TABLE IF EXISTS `' . self::$tableName . '`');
        self::$pdo->exec('CREATE TABLE `' . self::$tableName . '` (
                `session_id` varchar(32) NOT NULL default \'\',
                `hash` varchar(32) NOT NULL default \'\',
                `session_data` blob NOT NULL,
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
    #[TestDox('A locked session blocks a second request, but still allows a read-only one')]
    public function testSessionLock(): void
    {
        // First we start a long-running process to lock the session.
        $env = array_merge(getenv(), [
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
    #[TestDox('Data written in one request is visible in the next, and read-only writes are discarded')]
    public function testSessionWrite(): void
    {
        // Open not read-only session and the data in another request.
        // Instead of closing session and opening it again, we keep it open and spawn a process to better reflect the real use case.
        $payloadNotToBeOverwritten = uniqid();
        $env = array_merge(getenv(), [
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
