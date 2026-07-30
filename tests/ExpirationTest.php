<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * When a session stops being usable and when its row stops being there - the two are separate questions, and the
 * library has to get both right.
 */
class ExpirationTest extends SessionTestCase
{
    /**
     * get_active_sessions() has to count only the sessions that have not expired yet, and - since it runs the garbage
     * collector first - it also has to remove the expired ones from the table.
     * Both halves matter: the method was silently broken once (commit a20702a called gc() without its argument, which is
     * a fatal ArgumentCountError), so the helper process is also checked for a clean exit.
     *
     * @see a20702a
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testGetActiveSessions($driver) {
        $this->driver = $driver;

        $this->seedSession('active-session-1', time() + 3600);
        $this->seedSession('active-session-2', time() + 3600);
        $this->seedSession('expired-session-1', time() - 10);
        $this->seedSession('expired-session-2', time() - 3600);

        // read-only, so the helper adds no row of its own and the count depends on the seeded rows alone
        $process = $this->runHelper([
            'READ_ONLY' => 'yes',
            'GET_ACTIVE_SESSIONS' => 'yes',
        ]);

        $output = $process->output();
        $this->assertSame(
            1,
            preg_match('/\{"active_sessions":(.*?)\}/', $output, $matches),
            'get_active_sessions() produced no output. Got: ' . $output . $process->output()
        );

        // loose comparison - the value comes straight out of the database driver, which may hand back a string
        $this->assertEquals(2, json_decode($matches[1]), 'Wrong number of active sessions reported.');

        // the two expired rows have to be gone, the two active ones untouched
        $this->assertSame(['active-session-1', 'active-session-2'], $this->sessionIds(), 'Expired sessions were not garbage-collected.');
    }

    /**
     * gc() takes a $maxlifetime argument because SessionHandlerInterface requires one, but the library ignores it and
     * expires sessions by the session_expire column it wrote itself. Passing an absurd lifetime therefore has to change
     * nothing - if that ever stops being true, sessions would start outliving their stored expiration.
     *
     * @dataProvider drivers
     */
    public function testGarbageCollectorIgnoresMaxlifetime($driver) {
        $this->driver = $driver;

        $this->seedSession('active-session-1', time() + 3600);
        $this->seedSession('expired-session-1', time() - 10);

        // read-only, so the helper adds no row of its own
        $this->runHelper([
            'READ_ONLY' => 'yes',
            'RUN_GC' => '999999999',
        ]);

        $this->assertSame(['active-session-1'], $this->sessionIds(), 'gc() did not remove exactly the expired sessions.');
    }

    /**
     * Expiration is enforced on the way in, not only by the garbage collector: read() ignores rows whose session_expire
     * has passed. Without that, an expired session would stay usable for as long as nothing happened to trigger garbage
     * collection - which, with the default probability, can be a long time.
     *
     * @dataProvider drivers
     */
    public function testExpiredSessionIsNotReadable($driver) {
        $this->driver = $driver;

        $user_agent = 'Mozilla/5.0 (expiry test)';
        $payload = uniqid();

        // what the handler will rebuild and compare against: user agent + security code, with lock_to_ip left off
        $hash = md5($user_agent . 'sec-code');
        $data = TEST_SESSION_ID . '|' . serialize($payload);
        $env = ['USER_AGENT' => $user_agent, 'READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes'];

        // a row that has not expired reads back - this is what makes the second half meaningful
        $this->seedSession(TEST_SESSION_ID, time() + 3600, $hash, $data);
        $process = $this->runHelper($env);
        $this->assertSame($payload, $this->readSessionData($process), 'A seeded session that is still valid could not be read.');

        // the very same row, expired
        $this->clearSessions();
        $this->seedSession(TEST_SESSION_ID, time() - 10, $hash, $data);
        $process = $this->runHelper($env);
        $this->assertNull($this->readSessionData($process), 'An expired session was still readable.');
    }

    /**
     * The session lifetime given to the constructor is what read() later measures against, so it has to end up in the
     * session_expire column - but never below session.gc_maxlifetime, since the library takes the larger of the two.
     *
     * Taking the larger of the two is the fix: sessions used to time out the moment they were created under PHP 8.
     *
     * @see 091ee16 and https://github.com/stefangabos/Zebra_Session/issues/45
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testSessionLifetimeDecidesWhenASessionExpires($driver) {
        $this->driver = $driver;

        // phpunit and the helper read the same php.ini, so this is the value the library will be comparing against
        $gc_maxlifetime = (int)ini_get('session.gc_maxlifetime');

        // comfortably longer than gc_maxlifetime, so the lifetime is what wins
        $lifetime = $gc_maxlifetime + 3600;

        $before = time();
        $this->runHelper(['READ_ONLY' => 'no', 'SESSION_LIFETIME' => (string)$lifetime, 'WRITE_DATA_TO_SESSION' => uniqid()]);
        $after = time();

        $expire = $this->sessionExpire(TEST_SESSION_ID);
        $this->assertGreaterThanOrEqual($before + $lifetime, $expire, 'The session expires earlier than the lifetime it was given.');
        $this->assertLessThanOrEqual($after + $lifetime, $expire, 'The session expires later than the lifetime it was given.');

        // a lifetime shorter than gc_maxlifetime must not shorten the session - PHP would collect it either way
        $this->clearSessions();

        $before = time();
        $this->runHelper(['READ_ONLY' => 'no', 'SESSION_LIFETIME' => '1', 'WRITE_DATA_TO_SESSION' => uniqid()]);
        $after = time();

        $expire = $this->sessionExpire(TEST_SESSION_ID);
        $this->assertGreaterThanOrEqual($before + $gc_maxlifetime, $expire, 'A short lifetime was not raised to session.gc_maxlifetime.');
        $this->assertLessThanOrEqual($after + $gc_maxlifetime, $expire, 'A short lifetime ended up longer than session.gc_maxlifetime.');
    }

    /**
     * A lifetime of 0 is the constructor's own default, and it means something different from every other value: the
     * cookie lasts until the browser is closed, and how long the session itself survives is left to
     * session.gc_maxlifetime. Every other test here passes an explicit lifetime, so this is the configuration most
     * people actually run and the one nothing else covers.
     *
     * @see 3701e75, https://github.com/stefangabos/Zebra_Session/issues/40 and https://github.com/stefangabos/Zebra_Session/issues/5
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testDefaultSessionLifetime($driver) {
        $this->driver = $driver;

        // phpunit and the helper read the same php.ini
        $gc_maxlifetime = (int)ini_get('session.gc_maxlifetime');

        $before = time();
        $process = $this->runHelper([
            'READ_ONLY' => 'no',
            'SESSION_LIFETIME' => '0',
            'GET_INI' => 'yes',
            'WRITE_DATA_TO_SESSION' => uniqid(),
        ]);
        $after = time();

        $ini = $this->readIni($process);
        $this->assertSame('0', $ini['session.cookie_lifetime'] ?? null, 'The session cookie was given a lifetime instead of lasting until the browser closes.');

        // the stored expiration still has to be a real moment in the future, taken from gc_maxlifetime
        $expire = $this->sessionExpire(TEST_SESSION_ID);
        $this->assertGreaterThanOrEqual($before + $gc_maxlifetime, $expire, 'The session expires sooner than session.gc_maxlifetime.');
        $this->assertLessThanOrEqual($after + $gc_maxlifetime, $expire, 'The session expires later than session.gc_maxlifetime.');
    }
}
