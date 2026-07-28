<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Bugs that were reported, fixed, and have nothing in the scope-based test files that would notice them coming back.
 *
 * Historical bugs that a scope file already covers stay where they are - they belong with the behaviour they are about -
 * but every one of them carries a #[Group('regression')] tag and a @see line naming the commit or issue it came from, so
 *
 *     vendor/bin/phpunit -c tests --group regression
 *
 * runs the whole set no matter which file it lives in.
 *
 * Three fixes are deliberately absent because nothing out here can observe them - a test for any of them would pass
 * whether the fix were in place or not, which is worse than having no test at all:
 * - 39ea7c9, where stop() also deletes the session cookie: the helpers run under the CLI SAPI, which sends no headers, so
 *   there is no cookie to look at afterwards
 * - 40d04d2, the second argument of session_set_save_handler() - see testRegisteringTheHandlerIsNotDeprecated
 * - c903f43 (#11) and b782202, which removed uses of mysqli_ping() and other functions that later disappeared from PHP:
 *   what covers those is the GitHub Actions matrix running the whole suite against 8.1 through 8.4
 *
 * Every other test here was checked by mutating the fix back out of Zebra_Session.php and confirming it turns red, except
 * where its own docblock says otherwise. The one deliberate exception is testAStringifyingConnectionStillWorks, which is
 * there to catch the fix going too far rather than not far enough, and so passes either way by design.
 */
#[TestDox('Regressions')]
#[Group('regression')]
class RegressionTest extends SessionTestCase
{
    /**
     * MySQL 5.7.5 capped lock names at 64 characters, and silently misbehaved above that. The library hashes the session
     * id with sha1() rather than using it directly, so the name is always 48 characters no matter how long the id is.
     *
     * @see d1368b4 and https://github.com/stefangabos/Zebra_Session/issues/16
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('The lock name stays within MySQL\'s 64 character limit however long the session id is ($_dataName)')]
    public function testLockNameFitsWithinMysqlsLimit(string $driver): void
    {
        $this->driver = $driver;

        // longer than the limit on its own, so an id used verbatim would go straight over it
        $longSessionId = str_repeat('abcdefgh', 8);
        $lockName = $this->sessionLockName($longSessionId);

        $this->assertLessThanOrEqual(64, strlen($lockName), 'The lock name is too long for MySQL to hold on to.');

        // the session is held open rather than run to completion - the session_id column is a varchar(32), so an id this
        // long is only ever meant to reach the lock, not the table
        $process = $this->startHelper([
            'TEST_SESSION_ID' => $longSessionId,
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ]);
        $this->assertTrue(
            $this->waitForOutput($process, '{"session_start":"' . $longSessionId . '"}'),
            'The session could not be started with a long session id. Output: ' . $process->getOutput() . $process->getErrorOutput()
        );

        $this->assertTrue($this->lockIsHeld($lockName), 'MySQL did not take the lock for a long session id.');

        $process->stop();
    }

    /**
     * close() releases the lock and checks what MySQL made of it. RELEASE_LOCK returns 0 when the named lock is held by
     * a different connection, and that used to go unnoticed - the request would finish as though the session had been
     * closed cleanly while another request was, in fact, holding the session it thought it had just let go of.
     *
     * Getting MySQL to say 0 takes some arranging, since a named lock is only ever held by one connection at a time: the
     * helper drops its own lock and waits, this test picks it up on the phpunit connection, and only then does the helper
     * close its session.
     *
     * @see https://github.com/stefangabos/Zebra_Session/issues/52
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session lock that cannot be released is reported instead of passing silently ($_dataName)')]
    public function testFailureToReleaseTheSessionLockIsReported(string $driver): void
    {
        $this->driver = $driver;

        $lockName = $this->sessionLockName();

        $process = $this->startHelper([
            'READ_ONLY' => 'no',
            'RELEASE_LOCK_EARLY' => 'yes',
            'WRITE_DATA_TO_SESSION' => uniqid(),
        ]);

        $this->assertTrue(
            $this->waitForOutput($process, '{"lock_released_early":"' . self::$testingSid . '"}'),
            'The helper never got as far as releasing the lock, so this test proved nothing. Output: '
                . $process->getOutput() . $process->getErrorOutput()
        );

        // take the lock over on this connection, while the helper is paused - its close() then finds the lock belongs to
        // somebody else, which is the situation the fix is about
        $statement = self::$pdo->prepare('SELECT GET_LOCK(?, 5)');
        $statement->execute([$lockName]);
        $this->assertEquals(1, $statement->fetchColumn(), 'The test could not take the lock over from the helper.');

        $process->wait();

        // display_errors sends the uncaught exception to stdout in CLI, so both streams are searched
        $output = $process->getOutput() . $process->getErrorOutput();

        // let go of it again before asserting, so a failure here does not leave the lock held for the tests that follow
        self::$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);

        $this->assertStringContainsString(
            'Could not release session lock',
            $output,
            'A lock that could not be released was not reported. Output: ' . $output
        );
        $this->assertNotSame(0, $process->getExitCode(), 'The request finished cleanly despite failing to release its lock.');
    }

    /**
     * The other way RELEASE_LOCK reports that the session was not held: it answers NULL when there is no such lock at
     * all, rather than 0 for a lock belonging to somebody else. The check used to test for the integer 0 alone, so this
     * half went by unnoticed - a request whose lock had vanished mid-flight finished as though nothing had happened.
     *
     * Found while writing the test above, which is what the first version of it accidentally produced.
     *
     * @see https://github.com/stefangabos/Zebra_Session/issues/52 for the half that was already covered
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session lock that has vanished is reported just like one held by somebody else ($_dataName)')]
    public function testAVanishedSessionLockIsReported(string $driver): void
    {
        $this->driver = $driver;

        // nobody takes the lock over this time, so by the time close() asks, the lock simply does not exist
        $process = $this->startHelper([
            'READ_ONLY' => 'no',
            'RELEASE_LOCK_EARLY' => 'yes',
            'RELEASE_LOCK_EARLY_PAUSE' => '0',
            'WRITE_DATA_TO_SESSION' => uniqid(),
        ]);
        $process->wait();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertStringContainsString(
            'lock_released_early',
            $output,
            'The helper never got as far as releasing the lock, so this test proved nothing. Output: ' . $output
        );
        $this->assertStringContainsString(
            'Could not release session lock',
            $output,
            'A lock that had vanished was not reported. Output: ' . $output
        );
        $this->assertNotSame(0, $process->getExitCode(), 'The request finished cleanly despite its lock having vanished.');
    }

    /**
     * The library is handed a connection the caller built, and a caller is free to build it with STRINGIFY_FETCHES on -
     * plenty of applications do. Every column then comes back as a string, GET_LOCK and RELEASE_LOCK included, and the
     * checks on them used to compare against the integer 0 with ===, which a '0' never matches.
     *
     * The result was the worst possible one: a request that failed to get the session lock carried on as though it had,
     * and nothing anywhere said so. Only the PDO branch can be built this way, so this one does not run over both drivers.
     *
     * @return void
     */
    #[TestDox('A request on a stringifying PDO connection still fails loudly without the lock')]
    public function testAStringifyingConnectionStillNoticesAMissingLock(): void
    {
        $this->driver = 'pdo';

        // hold the session for the duration of this test
        $lockProcess = $this->startHelper([
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ]);
        $this->assertTrue(
            $this->waitForOutput($lockProcess, '{"session_start":"' . self::$testingSid . '"}'),
            'Unable to start the session that holds the lock. Timeout reached.'
        );

        // a second request, on a connection that hands everything back as a string, which gives up after a second
        $process = $this->startHelper([
            'READ_ONLY' => 'no',
            'PDO_STRINGIFY' => 'yes',
            'LOCK_TIMEOUT' => '1',
        ]);
        $process->wait();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertNotSame(0, $process->getExitCode(), 'The request carried on without the lock. Output: ' . $output);
        $this->assertStringContainsString('Could not obtain session lock', $output, 'The lock timeout was not reported. Output: ' . $output);

        $lockProcess->stop();
    }

    /**
     * The flip side of the above - a stringifying connection must not break the ordinary case either, since the check it
     * goes through is the same one.
     *
     * @return void
     */
    #[TestDox('A stringifying PDO connection stores and reads a session normally')]
    public function testAStringifyingConnectionStillWorks(): void
    {
        $this->driver = 'pdo';

        $payload = uniqid();
        $env = ['READ_ONLY' => 'no', 'PDO_STRINGIFY' => 'yes'];

        $this->runHelper($env + ['WRITE_DATA_TO_SESSION' => $payload]);

        $process = $this->runHelper($env + ['READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame($payload, $this->readSessionData($process), 'A session on a stringifying connection did not survive.');
    }

    /**
     * A request that dies before it can close its session - a fatal error, a timeout, someone killing the process - must
     * not leave the session locked for everyone who comes after it. MySQL drops the locks of a connection when that
     * connection goes away, which is what makes this work, so what is really being pinned down is that the library holds
     * its lock on the session's own connection and nowhere else.
     *
     * Unlike the rest of this file, this one has no single line in the library that can be mutated to turn it red - it
     * describes a property of the arrangement rather than a branch. It would go red if the library ever took its lock on
     * a connection of its own making instead of the one it was handed.
     *
     * @see https://github.com/stefangabos/Zebra_Session/issues/53
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A request killed before it can close its session leaves no lock behind ($_dataName)')]
    public function testAKilledRequestLeavesNoLockBehind(string $driver): void
    {
        $this->driver = $driver;

        $lockName = $this->sessionLockName();

        $process = $this->startHelper([
            'READ_ONLY' => 'no',
            'START_LONG_TASK' => 'yes',
        ]);
        $this->assertTrue(
            $this->waitForOutput($process, '{"session_start":"' . self::$testingSid . '"}'),
            'Unable to start the session that holds the lock. Timeout reached.'
        );
        $this->assertTrue($this->lockIsHeld($lockName), 'The session was started but never took a lock.');

        // SIGKILL, so that nothing the library registered - neither close() nor the shutdown function - gets to run
        $process->stop(0, SIGKILL);

        // the lock goes when MySQL notices the connection has gone, which is not instant
        $released = false;
        $start = microtime(true);
        while (microtime(true) - $start < 5) {
            if (!$this->lockIsHeld($lockName)) {
                $released = true;
                break;
            }
            usleep(50000);
        }

        $this->assertTrue($released, 'The session stayed locked after the request holding it was killed.');

        // and the session is usable again, which is the part that actually matters to the next visitor
        $this->runHelper(['READ_ONLY' => 'no', 'WRITE_DATA_TO_SESSION' => uniqid()]);
    }

    /**
     * Three settings the library used to take over and deliberately stopped touching: it was overriding choices the
     * application had made, and in the case of session.use_strict_mode it was turning on something that breaks setups
     * which hand out their own session ids.
     *
     * @see 8354675 and https://github.com/stefangabos/Zebra_Session/issues/37 for session.use_strict_mode
     * @see 70fc740 for session.gc_probability and session.gc_divisor
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('The constructor leaves use_strict_mode, gc_probability and gc_divisor alone ($_dataName)')]
    public function testTheConstructorLeavesTheSettingsItGaveUpOnAlone(string $driver): void
    {
        $this->driver = $driver;

        // values no php.ini would arrive at by itself, so finding them afterwards means nothing overwrote them
        $process = $this->runHelper([
            'READ_ONLY' => 'yes',
            'GET_INI' => 'yes',
            'USE_STRICT_MODE' => '0',
            'GC_PROBABILITY' => '7',
            'GC_DIVISOR' => '733',
        ]);

        $ini = $this->readIni($process);

        $this->assertSame('0', $ini['session.use_strict_mode'] ?? null, 'The library turned session.use_strict_mode back on.');
        $this->assertSame('7', $ini['session.gc_probability'] ?? null, 'The library overwrote session.gc_probability.');
        $this->assertSame('733', $ini['session.gc_divisor'] ?? null, 'The library overwrote session.gc_divisor.');
    }

    /**
     * The old session_set_save_handler() signature - a callback per method rather than an object - was deprecated in
     * PHP 8 and is due to stop working outright in PHP 9 or 10. The library takes the object form.
     *
     * Note what this does *not* cover: the second argument of that call, which 40d04d2 turned off so that PHP does not
     * register a shutdown function on top of the one the library already registers. Turning it back on changes nothing
     * observable from out here - the second close() asks MySQL to release a lock that no longer exists, RELEASE_LOCK
     * answers NULL, and close() only treats an integer 0 as a failure. Verified by mutating the argument back to true and
     * watching the whole suite stay green, so do not add a test for it that would pass either way.
     *
     * @see https://github.com/stefangabos/Zebra_Session/issues/49 and 40d04d2 for the untestable half
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Registering the session handler raises no deprecation notices ($_dataName)')]
    public function testRegisteringTheHandlerIsNotDeprecated(string $driver): void
    {
        $this->driver = $driver;

        $process = $this->runHelper(['READ_ONLY' => 'no', 'WRITE_DATA_TO_SESSION' => uniqid()]);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertStringNotContainsString('Deprecated', $output, 'Something an ordinary request does is deprecated. Output: ' . $output);
    }

    /**
     * stop() unsets the session and destroys it, and used to do so without first making sure there was a session to
     * destroy - "session_destroy(): Trying to destroy uninitialized session". The warning counts as output, which on a
     * real request also means the headers have already gone out by the time anything else happens.
     *
     * @see 258d701
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('stop() does not complain about an uninitialized session ($_dataName)')]
    public function testStopDoesNotComplainAboutAnUninitializedSession(string $driver): void
    {
        $this->driver = $driver;

        // no write beforehand, so there is no row and nothing was ever stored - the session exists only in this request
        $process = $this->runHelper(['READ_ONLY' => 'no', 'STOP_SESSION' => 'yes']);
        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertStringNotContainsString('uninitialized session', $output, 'stop() complained. Output: ' . $output);
        $this->assertStringNotContainsString('Warning', $output, 'stop() raised a warning. Output: ' . $output);
    }
}
