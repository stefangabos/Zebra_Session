<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * The base class every test class in this suite extends - it holds the database connection, the test table and the
 * plumbing for spawning and reading helper processes, so the test classes themselves hold nothing but tests.
 *
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
 * CAUTION: The tests rely on environmental variables, see tests/phpunit.xml.dist for the full list.
 */
abstract class SessionTestCase extends TestCase
{
    protected static \PDO $pdo;
    /**
     * @var Process[] Keep track of all started processes to clean up in tearDown
     */
    private array $activeProcesses = [];
    protected static ?string $testingSid = null;
    /**
     * @var string The driver the helper processes of the current test connect with - see driverProvider()
     */
    protected string $driver = 'pdo';
    protected static string $tableName = 'zebra_session_test_data';

    protected static string $sessionTestHelperPath = __DIR__ . '/../Fixtures/sessionTestHelper.php';

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

        $message = "Copy tests/phpunit.xml.dist to tests/phpunit.xml (git-ignored) and set the values below:\n";

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
            static::markTestSkipped('RUN_DB_TESTS is not enabled - see tests/phpunit.xml.dist');
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

    public static function tearDownAfterClass(): void
    {
        // $pdo is a typed static with no default, so it stays uninitialised when setUpBeforeClass skipped or failed early -
        // touching it in that state raises "typed static property must not be accessed before initialization"
        if (!isset(self::$pdo)) {
            return;
        }

        self::$pdo->exec('DROP TABLE IF EXISTS `' . self::$tableName . '`');
    }

    protected function setUp(): void
    {
        if (!filter_var(getenv('RUN_DB_TESTS'), FILTER_VALIDATE_BOOLEAN)) {
            $this->markTestSkipped('RUN_DB_TESTS is not enabled - see tests/phpunit.xml.dist');
        }
        self::$pdo->exec('TRUNCATE TABLE `' . self::$tableName . '`');
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
     * Starts a helper process and returns straight away, without waiting for it to finish - for the tests that need two
     * requests to be in flight at the same time, or that watch the output of a request while it is still running.
     *
     * @param array<string, string> $extraEnv Added on top of the environment phpunit was started with
     * @return Process
     */
    protected function startHelper(array $extraEnv): Process
    {
        return $this->startBackgroundProcess(
            command: [
                PHP_BINARY,
                self::$sessionTestHelperPath,
            ],
            env: array_merge(getenv(), ['DB_DRIVER' => $this->driver], $extraEnv)
        );
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
        $process = $this->startHelper($extraEnv);
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
     * The stored session data of a session, straight out of the blob column.
     *
     * @param string $sessionId
     * @return string
     */
    protected function storedSessionData(string $sessionId): string
    {
        $statement = self::$pdo->prepare('SELECT session_data FROM `' . self::$tableName . '` WHERE session_id = ?');
        $statement->execute([$sessionId]);

        return (string)$statement->fetchColumn();
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
