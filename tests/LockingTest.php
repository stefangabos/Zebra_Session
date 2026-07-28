<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * The session lock: who takes it, who does not, what happens to a request that cannot get it, and what it is ultimately
 * there to prevent.
 */
#[TestDox('Session locking')]
class LockingTest extends SessionTestCase
{
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
        $env = [
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ];

        $sessionLockProcess = $this->startHelper($env);

        $sessionLocked = $this->waitForOutput($sessionLockProcess, '{"session_start":"' . self::$testingSid . '"}');
        $this->assertTrue($sessionLocked, 'Unable to start a normal (locking) session. Timeout reached.');

        // The session is locked. We try to lock it again. It should time out.
        $env['START_LONG_TASK'] = 'no';
        $sessionHangProcess = $this->startHelper($env);
        $sessionHanged = $this->waitForOutput($sessionHangProcess, '{"session_start":"' . self::$testingSid . '"}', 2);
        $this->assertFalse($sessionHanged, 'Another process opened a locked session.');

        // this process is blocked inside GET_LOCK - it has to be stopped here, otherwise it would grab the lock the moment
        // the long-running process releases it, and the final step below would then fail for the wrong reason
        $sessionHangProcess->stop();

        // The session is still locked. We try to open a read-only session. It should normally start the session.
        $env['READ_ONLY'] = 'yes';
        $sessionROProcess = $this->startHelper($env);
        $sessionStarted = $this->waitForOutput($sessionROProcess, '{"session_start":"' . self::$testingSid . '"}');
        $this->assertTrue($sessionStarted, 'Unable to start read-only session. Timeout reached.');
        $sessionROProcess->stop();

        // Stopping the session locking process and running it again to verify the session has actually been released.
        // back to a normal session - a read-only one never takes a lock, so it would prove nothing here
        $sessionLockProcess->stop(0.1);
        $env['READ_ONLY'] = 'no';
        $sessionRelockProcess = $this->startHelper($env);

        $sessionLocked = $this->waitForOutput($sessionRelockProcess, '{"session_start":"' . self::$testingSid . '"}');
        $this->assertTrue($sessionLocked, "Unable to lock session after it's been closed. Timeout reached.");
        $sessionRelockProcess->stop();
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
        $slow = $this->startHelper([
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
            'LONG_TASK_CYCLES' => '2',
            'WRITE_KEY' => 'written_by_the_slow_request',
            'WRITE_DATA_TO_SESSION' => $slowPayload,
        ]);
        $this->assertTrue(
            $this->waitForOutput($slow, '{"session_start":"' . self::$testingSid . '"}'),
            'The slow request never started its session.'
        );

        // a second request for the same session, which has to wait its turn
        $fast = $this->startHelper([
            'READ_ONLY' => 'no',
            'WRITE_KEY' => 'written_by_the_fast_request',
            'WRITE_DATA_TO_SESSION' => $fastPayload,
        ]);

        $slow->wait();
        $fast->wait();

        $this->assertSame(0, $slow->getExitCode(), 'The slow request failed: ' . $slow->getErrorOutput() . $slow->getOutput());
        $this->assertSame(0, $fast->getExitCode(), 'The fast request failed: ' . $fast->getErrorOutput() . $fast->getOutput());

        $stored = $this->storedSessionData((string)self::$testingSid);

        $this->assertStringContainsString($slowPayload, $stored, 'The slow request\'s data was overwritten by the request that came after it.');
        $this->assertStringContainsString($fastPayload, $stored, 'The fast request\'s data never made it into the session.');
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
        $lockProcess = $this->startHelper([
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ]);
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

            $process = $this->startHelper([
                'READ_ONLY' => $readOnly,
                'START_LONG_TASK' => 'yes',
            ]);
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
        $lockProcess = $this->startHelper([
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ]);
        $this->assertTrue(
            $this->waitForOutput($lockProcess, '{"session_start":"' . self::$testingSid . '"}'),
            'Unable to start the session that holds the lock. Timeout reached.'
        );

        // a second request which gives up after a second instead of the usual minute
        $process = $this->startHelper([
            'READ_ONLY' => 'no',
            'LOCK_TIMEOUT' => '1',
        ]);
        $process->wait();

        // display_errors sends the uncaught exception to stdout in CLI, so both streams are searched
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), 'The request carried on without ever getting the lock. Output: ' . $output);
        $this->assertStringContainsString('Could not obtain session lock', $output, 'The lock timeout was not reported. Output: ' . $output);

        $lockProcess->stop();
    }
}
