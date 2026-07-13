<?php

/**
 * Lightweight SQLite-backed harness for session-handler integration tests.
 *
 * It provisions the session table and exposes counters for the SQLite lock
 * functions so the tests can assert on lock acquisition and release behavior.
 */
final class SqliteSessionHarness
{
    /** @var PDO */
    public $pdo;

    /** @var array<int, array{0:string,1:int}> */
    public $lockCalls = array();

    /** @var array<int, string> */
    public $releaseCalls = array();

    public function __construct()
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('
            CREATE TABLE `session_data` (
                `session_id` varchar(32) NOT NULL,
                `hash` varchar(32) NOT NULL,
                `session_data` blob NOT NULL,
                `session_expire` int(11) NOT NULL,
                PRIMARY KEY (`session_id`)
            )
        ');

        if (!method_exists($this->pdo, 'sqliteCreateFunction')) {
            throw new RuntimeException('PDO SQLite functions are required for these tests.');
        }

        $this->pdo->sqliteCreateFunction('GET_LOCK', array($this, 'getLock'), 2);
        $this->pdo->sqliteCreateFunction('RELEASE_LOCK', array($this, 'releaseLock'), 1);
    }

    public function getLock(string $name, int $timeout): int
    {
        $this->lockCalls[] = array((string) $name, (int) $timeout);

        return 1;
    }

    public function releaseLock(string $name): int
    {
        $this->releaseCalls[] = (string) $name;

        return 1;
    }

}
