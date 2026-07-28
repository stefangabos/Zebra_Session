<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Session fixation and session hijacking protection - the library stores a hash of "who started this session" next to
 * the session itself, and this is what that hash is worth.
 */
#[TestDox('Session hijacking protection')]
class SecurityTest extends SessionTestCase
{
    /**
     * The library stores a hash of "who started this session" next to the session itself and rebuilds it on every read.
     * If the two do not match the session is thrown away. That is the whole point of the library over PHP's own handler,
     * so each ingredient of that hash gets the same treatment here.
     *
     * The last step matters as much as the middle one: a session that is merely hidden from the impostor while remaining
     * usable by the original visitor would still be a hijackable session.
     *
     * @param array<string, string> $identity Environment describing the visitor that starts the session
     * @param array<string, string> $changedIdentity The same, with one ingredient of the hash changed
     * @param string $what The changed ingredient, for assertion messages
     * @return void
     */
    protected function assertSessionIsInvalidatedBy(array $identity, array $changedIdentity, string $what): void
    {
        $payload = uniqid();

        // the original visitor stores something
        $this->runHelper($identity + ['READ_ONLY' => 'no', 'WRITE_DATA_TO_SESSION' => $payload]);

        // ...and gets it back on the next request - without this the rest of the test would pass even if the session
        // never worked in the first place
        $process = $this->runHelper($identity + ['READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame($payload, $this->readSessionData($process), 'The session could not be read back with an unchanged ' . $what . '.');

        // a request with a different identity must not see it
        $process = $this->runHelper($changedIdentity + ['READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertNull($this->readSessionData($process), 'A changed ' . $what . ' was handed the session data.');

        // and the data is gone for good, not just hidden from the impostor
        $process = $this->runHelper($identity + ['READ_ONLY' => 'no', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertNull($this->readSessionData($process), 'The session survived being read with a changed ' . $what . '.');
    }

    /**
     * The user agent half was broken once in the other direction: a session became unusable to its own visitor if the
     * user agent changed after initialization.
     *
     * @see e948731
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[Group('regression')]
    #[TestDox('A session is invalidated when the user agent changes ($_dataName)')]
    public function testSessionIsInvalidatedWhenTheUserAgentChanges(string $driver): void
    {
        $this->driver = $driver;

        $this->assertSessionIsInvalidatedBy(
            ['USER_AGENT' => 'Mozilla/5.0 (the visitor who started the session)'],
            ['USER_AGENT' => 'Mozilla/5.0 (somebody else entirely)'],
            'user agent'
        );
    }

    /**
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session is invalidated when the security code changes ($_dataName)')]
    public function testSessionIsInvalidatedWhenTheSecurityCodeChanges(string $driver): void
    {
        $this->driver = $driver;

        $this->assertSessionIsInvalidatedBy(
            ['SECURITY_CODE' => 'sEcUr1tY_c0dE'],
            ['SECURITY_CODE' => 'a different security code'],
            'security code'
        );
    }

    /**
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('A session is invalidated when the IP address changes and lock_to_ip is on ($_dataName)')]
    public function testSessionIsInvalidatedWhenTheIpChanges(string $driver): void
    {
        $this->driver = $driver;

        $this->assertSessionIsInvalidatedBy(
            ['LOCK_TO_IP' => 'yes', 'REMOTE_ADDR' => '198.51.100.1'],
            ['LOCK_TO_IP' => 'yes', 'REMOTE_ADDR' => '198.51.100.2'],
            'IP address'
        );
    }

    /**
     * lock_to_ip can also be a callable, which is what makes the library usable behind a load balancer or a proxy: the
     * value that identifies the visitor is then whatever the callable returns rather than REMOTE_ADDR.
     *
     * It replaced two earlier attempts at the same problem, which is why the callable is the form that has to keep
     * working - the fixes for #54 and #43 were reverted in favour of it.
     *
     * @see 4fc876d, 38b6bb9 and https://github.com/stefangabos/Zebra_Session/issues/56
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[Group('regression')]
    #[TestDox('A session is invalidated when the value returned by the lock_to_ip callable changes ($_dataName)')]
    public function testSessionIsInvalidatedWhenTheLockToIpCallableReturnsSomethingElse(string $driver): void
    {
        $this->driver = $driver;

        $this->assertSessionIsInvalidatedBy(
            ['LOCK_TO_IP' => 'callable:198.51.100.1'],
            ['LOCK_TO_IP' => 'callable:198.51.100.2'],
            'value returned by the lock_to_ip callable'
        );
    }

    /**
     * Locking to the user agent is on by default, but turning it off has to actually turn it off - otherwise the option
     * would be doing nothing and nobody would notice, since the default behaviour would still look correct.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Turning off lock_to_user_agent lets a session survive a different user agent ($_dataName)')]
    public function testLockToUserAgentCanBeTurnedOff(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();
        $env = ['READ_ONLY' => 'no', 'LOCK_TO_USER_AGENT' => 'no'];

        $this->runHelper($env + ['USER_AGENT' => 'Mozilla/5.0 (the browser that started it)', 'WRITE_DATA_TO_SESSION' => $payload]);

        $process = $this->runHelper($env + ['USER_AGENT' => 'Mozilla/5.0 (a completely different browser)', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame(
            $payload,
            $this->readSessionData($process),
            'The session was thrown away over a changed user agent even though lock_to_user_agent was off.'
        );
    }

    /**
     * REMOTE_ADDR is not always there - command line scripts have none, and neither do some SAPI configurations. Reading
     * it blindly raised a warning, and since the warning counts as output it also stopped PHP from sending the session
     * cookie for that request.
     *
     * @see 1f2a52d
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[Group('regression')]
    #[TestDox('Locking to the IP address copes with REMOTE_ADDR not being set ($_dataName)')]
    public function testLockToIpCopesWithoutARemoteAddress(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();

        // the helper removes $_SERVER['REMOTE_ADDR'] outright - passing an empty one through the environment would not do,
        // since PHP copies environment variables into $_SERVER and the key would still be there
        $env = ['READ_ONLY' => 'no', 'LOCK_TO_IP' => 'yes', 'UNSET_REMOTE_ADDR' => 'yes'];

        $process = $this->runHelper($env + ['WRITE_DATA_TO_SESSION' => $payload]);
        $this->assertStringNotContainsString(
            'REMOTE_ADDR',
            $process->getOutput() . $process->getErrorOutput(),
            'Locking to the IP address complained about REMOTE_ADDR not being set.'
        );

        // and the session still works - the missing address just contributes nothing to the hash
        $process = $this->runHelper($env + ['READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame($payload, $this->readSessionData($process), 'The session was unusable without a REMOTE_ADDR.');
    }

    /**
     * The other half of the callable form, and the reason it exists: when the callable decides what identifies the
     * visitor, REMOTE_ADDR changing between requests - which is exactly what happens behind a load balancer - must not
     * cost the visitor their session.
     *
     * @see 4fc876d, 38b6bb9 and https://github.com/stefangabos/Zebra_Session/issues/56
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[Group('regression')]
    #[TestDox('A lock_to_ip callable keeps the session alive across a changing REMOTE_ADDR ($_dataName)')]
    public function testLockToIpCallableSurvivesAChangingRemoteAddress(string $driver): void
    {
        $this->driver = $driver;

        $payload = uniqid();
        $identity = ['LOCK_TO_IP' => 'callable:198.51.100.1', 'READ_ONLY' => 'no'];

        $this->runHelper($identity + ['REMOTE_ADDR' => '203.0.113.1', 'WRITE_DATA_TO_SESSION' => $payload]);

        // same visitor as far as the callable is concerned, different address as far as the server is concerned
        $process = $this->runHelper($identity + ['REMOTE_ADDR' => '203.0.113.2', 'READ_DATA_FROM_SESSION' => 'yes']);
        $this->assertSame(
            $payload,
            $this->readSessionData($process),
            'The session was lost when REMOTE_ADDR changed, even though the lock_to_ip callable returned the same value.'
        );
    }
}
