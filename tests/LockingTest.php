<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * The session lock: who takes it, who does not, what happens to a request that cannot get it, and what it is ultimately
 * there to prevent.
 */
class LockingTest extends SessionTestCase
{
    /**
     * Test spawns PHP processes to verify session locking for concurrent requests.
     * A long-running process is started to lock the session.
     * Then we verify that a concurrent session cannot be opened, unless it's read-only.
     * Finally, we terminate the locking process and verify the session has been unlocked.
     *
     * @dataProvider drivers
     */
    public function testSessionLock($driver) {
        $this->driver = $driver;

        // First we start a long-running process to lock the session.
        $env = [
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ];

        $session_lock_process = $this->startHelper($env);

        $session_locked = $session_lock_process->waitForOutput('{"session_start":"' . TEST_SESSION_ID . '"}');
        $this->assertTrue($session_locked, 'Unable to start a normal (locking) session. Timeout reached.');

        // The session is locked. We try to lock it again. It should time out.
        $env['START_LONG_TASK'] = 'no';
        $session_hang_process = $this->startHelper($env);
        $session_hanged = $session_hang_process->waitForOutput('{"session_start":"' . TEST_SESSION_ID . '"}', 2);
        $this->assertFalse($session_hanged, 'Another process opened a locked session.');

        // stopped here so that it cannot take the lock the moment the long-running process lets go of it
        $session_hang_process->stop();

        // The session is still locked. We try to open a read-only session. It should normally start the session.
        $env['READ_ONLY'] = 'yes';
        $session_read_only_process = $this->startHelper($env);
        $session_started = $session_read_only_process->waitForOutput('{"session_start":"' . TEST_SESSION_ID . '"}');
        $this->assertTrue($session_started, 'Unable to start read-only session. Timeout reached.');
        $session_read_only_process->stop();

        // release the lock and take it again, with a normal session - a read-only one takes no lock at all
        $session_lock_process->stop(0.1);
        $env['READ_ONLY'] = 'no';
        $session_relock_process = $this->startHelper($env);

        $session_locked = $session_relock_process->waitForOutput('{"session_start":"' . TEST_SESSION_ID . '"}');
        $this->assertTrue($session_locked, "Unable to lock session after it's been closed. Timeout reached.");
        $session_relock_process->stop();
    }

    /**
     * What the locking is ultimately for. Two requests writing different things to the same session must both survive -
     * without the lock the second one reads the session before the first one has written it, and then overwrites it,
     * losing the first one's work.
     *
     * @dataProvider drivers
     */
    public function testConcurrentWritesDoNotLoseData($driver) {
        $this->driver = $driver;

        $slow_payload = uniqid();
        $fast_payload = uniqid();

        // a request that holds the session for a couple of seconds and only then writes
        $slow = $this->startHelper([
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
            'LONG_TASK_CYCLES' => '2',
            'WRITE_KEY' => 'written_by_the_slow_request',
            'WRITE_DATA_TO_SESSION' => $slow_payload,
        ]);
        $this->assertTrue(
            $slow->waitForOutput('{"session_start":"' . TEST_SESSION_ID . '"}'),
            'The slow request never started its session.'
        );

        // a second request for the same session, which has to wait its turn
        $fast = $this->startHelper([
            'READ_ONLY' => 'no',
            'WRITE_KEY' => 'written_by_the_fast_request',
            'WRITE_DATA_TO_SESSION' => $fast_payload,
        ]);

        $slow->wait();
        $fast->wait();

        $this->assertSame(0, $slow->exitCode(), 'The slow request failed: ' . $slow->output());
        $this->assertSame(0, $fast->exitCode(), 'The fast request failed: ' . $fast->output());

        $stored = $this->storedSessionData(TEST_SESSION_ID);

        $this->assertStringContainsString($slow_payload, $stored, 'The slow request\'s data was overwritten by the request that came after it.');
        $this->assertStringContainsString($fast_payload, $stored, 'The fast request\'s data never made it into the session.');
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
     * @see dfb2873
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testReadOnlyRequestFinishesCleanlyWhileTheSessionIsLocked($driver) {
        $this->driver = $driver;

        // hold the session for the duration of this test
        $lock_process = $this->startHelper([
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ]);
        $this->assertTrue(
            $lock_process->waitForOutput('{"session_start":"' . TEST_SESSION_ID . '"}'),
            'Unable to start the session that holds the lock. Timeout reached.'
        );

        // a read-only request, this time allowed to run all the way through its shutdown
        $process = $this->runHelper(['READ_ONLY' => 'yes', 'READ_DATA_FROM_SESSION' => 'yes']);

        $this->assertStringNotContainsString(
            'Could not release session lock',
            $process->output(),
            'A read-only request tried to release a lock it never took.'
        );

        $lock_process->stop();
    }

    /**
     * The flip side of the above: a read-only session must not take the lock at all, otherwise it would block the very
     * requests it is meant to run alongside. Asked straight of MySQL rather than inferred from timing.
     *
     * @dataProvider drivers
     */
    public function testReadOnlySessionTakesNoLock($driver) {
        $this->driver = $driver;

        // the name the library derives from the session id - see read()
        $lock_name = 'session_' . sha1(TEST_SESSION_ID);

        foreach (['yes' => false, 'no' => true] as $read_only => $lock_expected) {

            $process = $this->startHelper([
                'READ_ONLY' => $read_only,
                'START_LONG_TASK' => 'yes',
            ]);
            $this->assertTrue(
                $process->waitForOutput('{"readonly":"' . $read_only . '"}'),
                'Unable to start the session. Timeout reached.'
            );

            // IS_USED_LOCK returns the id of the connection holding the lock, or NULL when nobody holds it
            $statement = self::$pdo->prepare('SELECT IS_USED_LOCK(?)');
            $statement->execute([$lock_name]);
            $holder = $statement->fetchColumn();

            $this->assertSame(
                $lock_expected,
                $holder !== null && $holder !== false,
                $read_only === 'yes'
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
     * @see https://github.com/stefangabos/Zebra_Session/issues/52 - the other half of that fix, a lock that cannot be
     *      released, is in RegressionTest
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testLockTimeoutIsReported($driver) {
        $this->driver = $driver;

        // hold the lock for as long as this test needs it
        $lock_process = $this->startHelper([
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ]);
        $this->assertTrue(
            $lock_process->waitForOutput('{"session_start":"' . TEST_SESSION_ID . '"}'),
            'Unable to start the session that holds the lock. Timeout reached.'
        );

        // a second request, with a one second lock timeout
        $process = $this->startHelper([
            'READ_ONLY' => 'no',
            'LOCK_TIMEOUT' => '1',
        ]);
        $process->wait();

        // display_errors sends the uncaught exception to stdout in CLI, so both streams are searched
        $output = $process->output();

        $this->assertNotSame(0, $process->exitCode(), 'The request carried on without ever getting the lock. Output: ' . $output);
        $this->assertStringContainsString('Could not obtain session lock', $output, 'The lock timeout was not reported. Output: ' . $output);

        $lock_process->stop();
    }
}
