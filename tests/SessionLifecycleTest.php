<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Starting, taking over, regenerating, destroying and stopping a session - everything that changes whether a session
 * exists at all, and under which id.
 */
#[TestDox('Session lifecycle')]
class SessionLifecycleTest extends SessionTestCase
{
    /**
     * Being instantiated while a session is already running has to work - the class has always meant to handle it, and
     * plenty of code calls session_start() somewhere before reaching the line that sets the library up.
     *
     * It did not work: PHP refuses both ini_set() on session settings and session_set_save_handler() while a session is
     * active, and the constructor only got rid of the running session *after* making those calls. Every one of them
     * failed with a warning, and the application carried on using PHP's own file based handler without ever being told.
     *
     * @see e46a02f, and 36a28d7 for the first attempt at the same thing
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[Group('regression')]
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
     *
     * Dropping the old row is the part with the history: session_regenerate_id(true) went into an infinite loop under
     * PHP 7, so the destroy-the-old-session argument is exactly what has to keep working here.
     *
     * @see bcae14a and 4dde64d
     *
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[Group('regression')]
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
        $this->assertStringContainsString($payload, $this->storedSessionData($newSessionId), 'Session data was lost when the id was regenerated.');
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
}
