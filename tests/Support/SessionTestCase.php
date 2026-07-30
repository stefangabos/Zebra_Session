<?php

/**
 * The base class every test class in this suite extends.
 *
 * The tests run against a real MySQL instance - the handler depends on MySQL's GET_LOCK/RELEASE_LOCK, so
 * there is no in-memory substitute that would exercise the locking behaviour at all.
 *
 * They also spawn PHP processes rather than driving the library in-process, for two reasons:
 *
 * 1. it is what a real use looks like - concurrent requests, each with its own connection, which is the
 *    only arrangement in which a session lock means anything
 * 2. a new Zebra_Session registers itself as the session handler, so several of them in one process would
 *    tread on each other
 *
 * Everything shared lives here - the connection the assertions read through, a clean table before each
 * test, the helper processes and the readers that pick their output apart - so that the test classes hold
 * nothing but tests.
 *
 * There is deliberately no probe class - every member of Zebra_Session is private, and a subclass cannot
 * reach private. These tests assert against the database and against what the child processes printed.
 *
 * CAUTION: the suite relies on environment variables - see tests/phpunit.xml.dist for the full list.
 */
abstract class SessionTestCase extends PHPUnit\Framework\TestCase
{
    /**
     * The connection the assertions read and seed through - always PDO, whichever driver the helper of the
     * moment is using. Test infrastructure, not part of what is under test.
     *
     * @var PDO|null
     */
    protected static $pdo;

    /**
     * The driver the helper processes of the current test connect with - see the "drivers" provider
     *
     * @var string
     */
    protected $driver = 'pdo';

    /**
     * Children started during a test, killed in tearDown() so that a failing assertion cannot leave one
     * running and holding a session lock for whatever runs next
     *
     * @var array<ChildProcessHandle>
     */
    private $children = [];

    public static function setUpBeforeClass(): void {

        $dsn = 'mysql:host=' . TEST_DB_HOST . ';port=' . TEST_DB_PORT . ';dbname=' . TEST_DB_NAME . ';charset=utf8mb4';

        self::$pdo = new PDO($dsn, TEST_DB_USER, TEST_DB_PASS);

        self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    }

    public static function tearDownAfterClass(): void {

        self::$pdo = null;

    }

    protected function setUp(): void {

        $this->resetState();

    }

    protected function tearDown(): void {

        foreach ($this->children as $child) if ($child->isRunning()) $child->kill();

        $this->children = [];

    }

    /**
     * Empties the session table, so that every test starts from nothing.
     *
     * DELETE rather than TRUNCATE - TRUNCATE is a DDL statement and costs several milliseconds a time, for a
     * table that rarely holds more than a couple of rows.
     *
     * @return  void
     */
    protected function resetState() {

        self::$pdo->exec('DELETE FROM `' . TEST_DB_TABLE . '`');

    }

    /**
     * The library talks to MySQL either through PDO or through mysqli, and query() has a separate
     * implementation for each - different parameter binding, different way of counting rows, different
     * error handling. Everything the suite checks therefore has to be checked against both.
     *
     * @return  array<string, array<string>>
     */
    public function drivers() {
        return [
            'PDO'       => ['pdo'],
            'mysqli'    => ['mysqli'],
        ];
    }

    /**
     * Starts a helper process and returns straight away - for the tests that need two requests in flight at
     * once, or that watch what a request prints while it is still running.
     *
     * @param   array<string, string>   $env    added on top of the environment phpunit was started with
     *
     * @return  ChildProcessHandle
     */
    protected function startHelper($env) {

        $child = ChildProcess::start(TEST_SESSION_HELPER, array_merge(['DB_DRIVER' => $this->driver], $env));

        $this->children[] = $child;

        return $child;

    }

    /**
     * Runs a helper to completion and asserts it did not die on the way, so that a fatal error there is
     * reported as itself and not as a confusing assertion failure further down.
     *
     * @param   array<string, string>   $env    added on top of the environment phpunit was started with
     *
     * @return  ChildProcessHandle
     */
    protected function runHelper($env) {

        $child = $this->startHelper($env);

        $child->wait();

        $this->assertSame(0, $child->exitCode(), 'Helper process failed: ' . $child->output());

        return $child;

    }

    /**
     * The session ids currently in the table, sorted, so assertions can compare against a plain array.
     *
     * @return  array<string>
     */
    protected function sessionIds() {

        $ids = self::$pdo->query('SELECT session_id FROM `' . TEST_DB_TABLE . '` ORDER BY session_id')->fetchAll(PDO::FETCH_COLUMN);

        return array_map('strval', $ids);

    }

    /**
     * The stored session data of a session, straight out of the blob column.
     *
     * @param   string  $session_id
     *
     * @return  string
     */
    protected function storedSessionData($session_id) {

        $statement = self::$pdo->prepare('SELECT session_data FROM `' . TEST_DB_TABLE . '` WHERE session_id = ?');
        $statement->execute([$session_id]);

        return (string)$statement->fetchColumn();

    }

    /**
     * The moment a stored session is set to expire.
     *
     * @param   string  $session_id
     *
     * @return  int
     */
    protected function sessionExpire($session_id) {

        $statement = self::$pdo->prepare('SELECT session_expire FROM `' . TEST_DB_TABLE . '` WHERE session_id = ?');
        $statement->execute([$session_id]);

        return (int)$statement->fetchColumn();

    }

    /**
     * The name the library derives from a session id for its MySQL lock - see read().
     *
     * @param   string|null $session_id     defaults to the session id the helper processes use
     *
     * @return  string
     */
    protected function sessionLockName($session_id = null) {

        return 'session_' . sha1($session_id === null ? TEST_SESSION_ID : $session_id);

    }

    /**
     * Whether anyone currently holds the given lock - IS_USED_LOCK returns the id of the connection holding
     * it, or NULL when nobody does.
     *
     * Impure - two calls with the same lock name can give two different answers, which is what the tests
     * waiting for a lock to be released depend on.
     *
     * @phpstan-impure
     *
     * @param   string  $lock_name
     *
     * @return  bool
     */
    protected function lockIsHeld($lock_name) {

        $statement = self::$pdo->prepare('SELECT IS_USED_LOCK(?)');
        $statement->execute([$lock_name]);

        $holder = $statement->fetchColumn();

        return $holder !== null && $holder !== false;

    }

    /**
     * Writes a session row straight into the table, bypassing the handler - the point is to control
     * session_expire, which the handler always derives from time() plus the session lifetime.
     *
     * @param   string      $session_id
     * @param   int         $expire         unix timestamp at which the session expires
     * @param   string|null $hash           the hash the handler will compare against; defaults to one no
     *                                      visitor can produce
     * @param   string      $data           session data in PHP's session serialization format
     *
     * @return  void
     */
    protected function seedSession($session_id, $expire, $hash = null, $data = '') {

        $statement = self::$pdo->prepare(
            'INSERT INTO `' . TEST_DB_TABLE . '` (session_id, hash, session_data, session_expire) VALUES (?, ?, ?, ?)'
        );

        $statement->execute([$session_id, $hash === null ? md5($session_id) : $hash, $data, $expire]);

    }

    /**
     * Empties the table mid-test, for tests that need to seed a second time.
     *
     * @return  void
     */
    protected function clearSessions() {

        self::$pdo->exec('DELETE FROM `' . TEST_DB_TABLE . '`');

    }

    /**
     * Pulls the session value out of a helper's output - the helper prints it keyed by the session id.
     *
     * @param   ChildProcessHandle  $child
     *
     * @return  string|null     null when the session held nothing, which is a result the tests assert on
     */
    protected function readSessionData($child) {

        $output = $child->output();

        $this->assertSame(
            1,
            preg_match('/\{"' . preg_quote(TEST_SESSION_ID, '/') . '":(.*?)\}/', $output, $matches),
            'The helper printed no session data at all. Got: ' . $output
        );

        $value = json_decode($matches[1]);

        return $value === null ? null : (string)$value;

    }

    /**
     * Pulls a base64 encoded session value out of a helper's output, for payloads that do not survive being
     * compared as JSON - anything with null bytes or invalid UTF-8 in it.
     *
     * @param   ChildProcessHandle  $child
     *
     * @return  string|null
     */
    protected function readSessionDataBase64($child) {

        $output = $child->output();

        $this->assertSame(
            1,
            preg_match('/"data_base64":(".*?"|null)/', $output, $matches),
            'The helper printed no session data at all. Got: ' . $output
        );

        $value = json_decode($matches[1]);

        return $value === null ? null : base64_decode((string)$value);

    }

    /**
     * Pulls the flash data values out of a helper's output.
     *
     * @param   ChildProcessHandle  $child
     *
     * @return  array<string, string|null>  the requested variables, null where the variable was not set
     */
    protected function readFlashData($child) {

        $output = $child->output();

        // a flat object, so matching up to the first closing brace is enough
        $this->assertSame(
            1,
            preg_match('/"flashdata":(\{[^{}]*\})/', $output, $matches),
            'The helper printed no flash data at all. Got: ' . $output
        );

        return (array)json_decode($matches[1], true);

    }

    /**
     * Pulls the reported ini settings out of a helper's output.
     *
     * @param   ChildProcessHandle  $child
     *
     * @return  array<string, string>
     */
    protected function readIni($child) {

        $output = $child->output();

        $this->assertSame(
            1,
            preg_match('/"ini":(\{[^{}]*\})/', $output, $matches),
            'The helper printed no ini settings at all. Got: ' . $output
        );

        return (array)json_decode($matches[1], true);

    }

    /**
     * Pulls what get_settings() returned out of a helper's output.
     *
     * @param   ChildProcessHandle  $child
     *
     * @return  array<string, string>
     */
    protected function readSettings($child) {

        $output = $child->output();

        // the settings are a flat array, so matching up to the first closing brace is enough
        $this->assertSame(
            1,
            preg_match('/"settings":(\{[^{}]*\})/', $output, $matches),
            'The helper printed no settings at all. Got: ' . $output
        );

        return (array)json_decode($matches[1], true);

    }

    /**
     * Runs the given callback and returns every PHP diagnostic it raised.
     *
     * Use this to assert that the library does its job without also warning, noticing or deprecating - a
     * good number of the bugs in these libraries are of the "it works, but it warns" kind, and on the newer
     * PHP versions today's deprecation is tomorrow's fatal error.
     *
     * @param   callable    $callback   the code to watch
     *
     * @return  array<string>           the messages raised, in the order they were raised
     */
    protected function diagnosticsRaisedBy($callback) {

        $raised = [];

        set_error_handler(function($number, $message) use (&$raised) {

            // a handler is called even for diagnostics the library deliberately silenced with "@", and
            // those are not something the user ever sees - error_reporting() is what tells them apart
            if (!(error_reporting() & $number)) return true;

            $raised[] = $message;

            return true;

        });

        try {
            call_user_func($callback);
        } catch (Exception $exception) {
            restore_error_handler();
            throw $exception;
        }

        restore_error_handler();

        return $raised;

    }

    /**
     * Asserts that the callback raised no PHP diagnostics at all.
     *
     * @param   callable    $callback
     * @param   string      $message
     *
     * @return  void
     */
    protected function assertRaisesNoDiagnostics($callback, $message = '') {

        $this->assertSame([], $this->diagnosticsRaisedBy($callback), $message);

    }
}
