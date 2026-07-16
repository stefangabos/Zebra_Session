<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Zebra_Session.php';
require_once __DIR__ . '/SqliteSessionHarness.php';

/**
 * Integration tests for the session handler using SQLite as a stand-in database.
 *
 * The tests exercise the public SessionHandlerInterface methods end to end:
 * opening sessions, reading stored data, writing new state, and destroying rows.
 */
final class SqliteHandlerIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        // Restoring default session handler (currently, Zebra_Session sets itself as a handler when instantiated).
        session_set_save_handler(new \SessionHandler(), true);
    }

    /**
     * Covers the full lifecycle of a fresh session: open, read, write, close, and destroy.
     */
    public function testCreatesWritesAndDestroysASession(): void
    {
        $oldUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestUA';

        try {
            $harness = $this->newHarness();
            $session = $this->newSession($harness);
            $sessionId = 'abc123';
            $sessionData = 'foo|s:3:"bar";';

            $this->assertTrue($session->open('', 'PHPSESSID'));
            $this->assertSame('', $session->read($sessionId));
            $this->assertCount(1, $harness->lockCalls);

            $this->assertTrue($session->write($sessionId, $sessionData));

            $row = $this->fetchSessionRow($harness->pdo, $sessionId);
            $this->assertNotFalse($row);
            $this->assertSame($sessionId, $row['session_id']);
            $this->assertSame($this->expectedHash('UnitTestUA'), $row['hash']);
            $this->assertSame($sessionData, $row['session_data']);
            $this->assertGreaterThan(time(), (int) $row['session_expire']);

            $this->assertTrue($session->close());
            $this->assertCount(1, $harness->releaseCalls);

            $this->assertTrue($session->destroy($sessionId));
            $this->assertFalse($this->fetchSessionRow($harness->pdo, $sessionId));
        } finally {
            if ($oldUserAgent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $oldUserAgent;
            }
        }
    }

    /**
     * Verifies that a previously stored session can be read back unchanged.
     */
    public function testReadsPreviouslyCreatedSession(): void
    {
        $oldUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestUA';

        try {
            $harness = $this->newHarness();
            $session = $this->newSession($harness);
            $sessionId = 'existing-session';
            $sessionData = 'user_id|i:42;';

            $this->insertSessionRow(
                $harness->pdo,
                $sessionId,
                $this->expectedHash('UnitTestUA'),
                $sessionData,
                time() + 3600
            );

            $this->assertTrue($session->open('', 'PHPSESSID'));
            $this->assertSame($sessionData, $session->read($sessionId));
            $this->assertCount(1, $harness->lockCalls);

            $this->assertTrue($session->close());
            $this->assertCount(1, $harness->releaseCalls);
        } finally {
            if ($oldUserAgent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $oldUserAgent;
            }
        }
    }

    /**
     * Verifies that an invalid session is rejected and removed from storage.
     */
    public function testReadReturnsEmptyStringForInvalidSessionAndDeletesRow(): void
    {
        $oldUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestUA';

        try {
            $harness = $this->newHarness();
            $session = $this->newSession($harness);
            $sessionId = 'invalid-session';

            $this->insertSessionRow(
                $harness->pdo,
                $sessionId,
                'wrong-hash',
                'foo|s:3:"bar";',
                time() + 3600
            );

            $this->assertTrue($session->open('', 'PHPSESSID'));
            $this->assertSame('', $session->read($sessionId));
            $this->assertCount(1, $harness->lockCalls);

            $this->assertTrue($session->close());
            $this->assertCount(1, $harness->releaseCalls);
            $this->assertFalse($this->fetchSessionRow($harness->pdo, $sessionId));
        } finally {
            if ($oldUserAgent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $oldUserAgent;
            }
        }
    }

    /**
     * Verifies that an expired session is rejected and removed from storage.
     */
    public function testReadReturnsEmptyStringForOutdatedSessionAndDeletesRow(): void
    {
        $oldUserAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $_SERVER['HTTP_USER_AGENT'] = 'UnitTestUA';

        try {
            $harness = $this->newHarness();
            $session = $this->newSession($harness);
            $sessionId = 'expired-session';

            $this->insertSessionRow(
                $harness->pdo,
                $sessionId,
                $this->expectedHash('UnitTestUA'),
                'foo|s:3:"bar";',
                time() - 3600
            );

            $this->assertTrue($session->open('', 'PHPSESSID'));
            $this->assertSame('', $session->read($sessionId));
            $this->assertCount(1, $harness->lockCalls);

            $this->assertTrue($session->close());
            $this->assertCount(1, $harness->releaseCalls);
            $this->assertFalse($this->fetchSessionRow($harness->pdo, $sessionId));
        } finally {
            if ($oldUserAgent === null) {
                unset($_SERVER['HTTP_USER_AGENT']);
            } else {
                $_SERVER['HTTP_USER_AGENT'] = $oldUserAgent;
            }
        }
    }

    private function newSession(SqliteSessionHarness $harness): Zebra_Session
    {
        return new Zebra_Session(
            $harness->pdo,
            'sec-code',
            3600,
            true,
            false,
            60,
            'session_data',
            false,
            false
        );
    }

    private function newHarness(): SqliteSessionHarness
    {
        return new SqliteSessionHarness();
    }

    private function insertSessionRow(PDO $pdo, string $sessionId, string $hash, string $sessionData, int $sessionExpire): void
    {
        $stmt = $pdo->prepare('
            INSERT INTO `session_data` (
                session_id,
                hash,
                session_data,
                session_expire
            ) VALUES (?, ?, ?, ?)
        ');

        $stmt->execute(array($sessionId, $hash, $sessionData, $sessionExpire));
    }

    private function fetchSessionRow(PDO $pdo, string $sessionId): mixed
    {
        $stmt = $pdo->prepare('
            SELECT
                session_id,
                hash,
                session_data,
                session_expire
            FROM
                `session_data`
            WHERE
                session_id = ?
            LIMIT 1
        ');

        $stmt->execute(array($sessionId));

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? false : $row;
    }

    private function expectedHash(string $userAgent): string
    {
        return md5($userAgent . 'sec-code');
    }
}
