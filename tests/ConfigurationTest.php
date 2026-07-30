<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * What the constructor is given and what it does with it - the ini settings it takes over, the table name it is pointed
 * at, and what get_settings() reports back.
 */
class ConfigurationTest extends SessionTestCase
{
    /**
     * Everything the library does needs a database connection, so being handed something else has to be refused right
     * away rather than failing somewhere deep in query().
     *
     * This one runs in the phpunit process rather than in a helper: the constructor throws before it registers itself as
     * the session handler, so there is nothing for it to leave behind.
     */
    public function testConstructorRejectsAnInvalidLink() {
        // a variable rather than the call inline, because the constructor takes $link by reference
        $link = $this->somethingThatIsNotAConnection();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Zebra_Session: No MySQL connection');

        new \Zebra_Session($link, 'sec-code');
    }

    /**
     * The constructor's $link has no native type, only a "\MySQLi|\PDO" docblock, so what actually arrives
     * there is whatever the caller passed. That is the path the test above covers, and saying so with a
     * mixed return is what makes it describable rather than a type error waiting to be argued about.
     *
     * @return  mixed
     */
    private function somethingThatIsNotAConnection() {
        return new \stdClass();
    }

    /**
     * The class documents a handful of ini settings it takes care of on the visitor's behalf, which is the sort of
     * promise that quietly stops being true.
     *
     * session.cookie_lifetime is the one with a history: it used to be pinned to 0, so a session could not outlive the
     * browser window no matter what lifetime the constructor was given.
     *
     * @see 3701e75, https://github.com/stefangabos/Zebra_Session/issues/40 and https://github.com/stefangabos/Zebra_Session/issues/5
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testConstructorSetsItsIniOptions($driver) {
        $this->driver = $driver;

        $process = $this->runHelper(['READ_ONLY' => 'yes', 'GET_INI' => 'yes', 'SESSION_LIFETIME' => '1234']);
        $ini = $this->readIni($process);

        $this->assertSame('1', $ini['session.cookie_httponly'] ?? null, 'The session cookie is exposed to client side scripting.');
        $this->assertSame('1', $ini['session.use_only_cookies'] ?? null, 'The session id may be passed around outside a cookie.');

        // note this is the lifetime as given, not the one clamped to session.gc_maxlifetime that the database sees
        $this->assertSame('1234', $ini['session.cookie_lifetime'] ?? null, 'The session cookie does not last as long as it was told to.');

        // no HTTPS here, so the secure flag has to stay off - setting it unconditionally would break plain HTTP sites
        $this->assertSame('0', $ini['session.cookie_secure'] ?? null, 'The session cookie was marked secure over a plain connection.');
    }

    /**
     * @see 1c614a1 and https://github.com/stefangabos/Zebra_Session/issues/18
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testCookieIsMarkedSecureOverHttps($driver) {
        $this->driver = $driver;

        $process = $this->runHelper(['READ_ONLY' => 'yes', 'GET_INI' => 'yes', 'HTTPS' => 'on']);
        $ini = $this->readIni($process);

        $this->assertSame('1', $ini['session.cookie_secure'] ?? null, 'The session cookie is not marked secure over HTTPS.');
    }

    /**
     * The table name is wrapped in backticks by the constructor, so passing one that is already wrapped - which is what
     * anyone copying the name out of a database client ends up doing - has to work just the same.
     *
     * @see ac5157e and https://github.com/stefangabos/Zebra_Session/issues/27
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testTableNameMayComeWrappedInBackticks($driver) {
        $this->driver = $driver;

        $payload = uniqid();
        $env = ['READ_ONLY' => 'no', 'DB_TABLE' => '`' . TEST_DB_TABLE . '`'];

        $this->runHelper($env + ['WRITE_DATA_TO_SESSION' => $payload]);

        $process = $this->runHelper($env + ['READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame($payload, $this->readSessionData($process), 'The session was not stored in the table the backticked name refers to.');

        // and it went into the table the test knows about, not one that got created along the way
        $this->assertSame([TEST_SESSION_ID], $this->sessionIds());
    }

    /**
     * @dataProvider drivers
     */
    public function testGetSettingsReportsTheGarbageCollectionSettings($driver) {
        $this->driver = $driver;

        $process = $this->runHelper([
            'READ_ONLY' => 'yes',
            'GET_SETTINGS' => 'yes',
            'GC_PROBABILITY' => '1',
            'GC_DIVISOR' => '100',
        ]);

        $settings = $this->readSettings($process);

        $this->assertSame('1', $settings['session.gc_probability'] ?? null);
        $this->assertSame('100', $settings['session.gc_divisor'] ?? null);
        $this->assertSame('1%', $settings['probability'] ?? null, 'The chance of the garbage collector running was computed wrong.');
        $this->assertArrayHasKey('session.gc_maxlifetime', $settings);
        $this->assertArrayHasKey('session.use_strict_mode', $settings);
    }

    /**
     * A divisor of 0 means "never collect garbage". It used to make get_settings() divide by zero, which is a fatal
     * error in PHP 8 - hence the check on the helper's exit code inside runHelper().
     *
     * @see 2ad3430 and https://github.com/stefangabos/Zebra_Session/issues/48
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testGetSettingsWithAZeroDivisor($driver) {
        $this->driver = $driver;

        $process = $this->runHelper([
            'READ_ONLY' => 'yes',
            'GET_SETTINGS' => 'yes',
            'GC_PROBABILITY' => '1',
            'GC_DIVISOR' => '0',
        ]);

        $settings = $this->readSettings($process);

        // PHP 8.4 refuses a divisor of 0 from both ini_set() and php.ini, so this case cannot be built there
        if (($settings['session.gc_divisor'] ?? null) !== '0') {
            $this->markTestSkipped(
                'This version of PHP does not allow session.gc_divisor to be 0 - it ended up as '
                . var_export($settings['session.gc_divisor'] ?? null, true)
            );
        }

        $this->assertSame('0%', $settings['probability'] ?? null, 'A divisor of 0 should mean the garbage collector never runs.');
    }
}
