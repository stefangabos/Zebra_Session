<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Tests that can only be performed on a real MySQL instance (i.e. cannot be done with in-memory SqLite).
 * These are integration tests, exercising only public session API,
 * except for read-only session which is a Zebra specific feature.
 *
 * CAUTION: The tests rely on environmental variables, see phpunit.db-tests.xml.dist for the full list.
 */
class MySqlSessionHandlerIntegrationTest extends TestCase
{
    private static \PDO $pdo;
    /**
     * @var Process[] Keep track of all started processes to clean up in tearDown
     */
    private array $activeProcesses = [];
    private static ?string $testingSid = null;

    private static string $sessionTestHelperPath = __DIR__ . '/fixtures/sessionTestHelper.php';

    public static function setUpBeforeClass(): void
    {
        if (getenv('RUN_DB_TESTS') == '') {
            static::markTestSkipped("RUN_DB_TESTS is set to 'false'");
        }

        self::$testingSid = getenv('TEST_SESSION_ID');

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
        self::$pdo->exec("DROP TABLE IF EXISTS `sessions`");
        self::$pdo->exec("CREATE TABLE `sessions` (
                `session_id` VARCHAR(64) NOT NULL,
                `hash` VARCHAR(64) NOT NULL,
                `session_data` LONGBLOB NOT NULL,
                `session_expire` INT NOT NULL DEFAULT '0',
                `session_expire_date` DATETIME AS (from_unixtime(`session_expire`)) virtual,
                PRIMARY KEY (`session_id`) )
        ");
    }

    /**
     * Test spawns PHP processes to verify session locking for concurrent requests.
     * A long-running process is started to lock the session.
     * Then we verify that a concurrent session cannot be opened, unless it's read-only.
     * Finally, we terminate the locking process and verify the session has been unlocked.
     * @return void
     */
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

        $sessionLocked = $this->waitForOutput($sessionLockProcess, '{"session_start":"' . self::$testingSid . '"}', 1);
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
        $sessionHanged = $this->waitForOutput($sessionHangProcess, '{"session_start":"' . self::$testingSid . '"}', 1);
        $this->assertFalse($sessionHanged, 'Another process opened a locked session.');
        $sessionLockProcess->stop();

        // The session is locked. We try to open a read-only session. It should normally start the session.
        $env['READ_ONLY'] = 'yes';
        $sessionROProcess = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );
        $sessionStarted = $this->waitForOutput($sessionROProcess, '{"session_start":"' . self::$testingSid . '"}', 1);
        $this->assertTrue($sessionStarted, 'Unable to start read-only session. Timeout reached.');
        $sessionROProcess->stop();

        // Stopping the session locking process and running it again to verify the session has actually been released.
        $sessionLockProcess->stop(0.1);
        $sessionLockProcess = $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: $env
        );

        $sessionLocked = $this->waitForOutput($sessionLockProcess, '{"session_start":"' . self::$testingSid . '"}', 1);
        $this->assertTrue($sessionLocked, "Unable to lock session after it's been closed. Timeout reached.");
    }

    public static function tearDownAfterClass(): void
    {
        self::$pdo->exec("DROP TABLE IF EXISTS `sessions`");
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
        if (getenv('RUN_DB_TESTS') == '') {
            $this->markTestSkipped("RUN_DB_TESTS is set to 'false'");
        }
        self::$pdo->exec("TRUNCATE TABLE sessions");
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
     * @param int $timeoutInSeconds
     * @return bool
     */
    protected function waitForOutput(Process $process, string $needle, int $timeoutInSeconds = 5): bool
    {
        $start = time();
        while (time() - $start < $timeoutInSeconds) {
            if (str_contains($process->getOutput(), $needle)) {
                return true;
            }
            usleep(50000); // Sleep for 50ms to prevent CPU pegging
        }
        return false;
    }
}
