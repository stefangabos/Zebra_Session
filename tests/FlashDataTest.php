<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Flash data - session variables that are available for exactly one further request and are then deleted.
 */
#[TestDox('Flash data')]
class FlashDataTest extends SessionTestCase
{
    /**
     * Both ways of starting the session have to behave the same as far as flash data is concerned.
     *
     * They did not: when the caller starts the session itself, the class is instantiated before there is a session to
     * read, so flash data used to go unnoticed on every subsequent request and the variables were never deleted.
     *
     * @return array<string, array<string>>
     */
    public static function sessionStartProvider(): array
    {
        $cases = [];

        foreach (self::driverProvider() as $driverName => $driver) {
            $cases[$driverName . ', session started by the library'] = [$driver[0], 'yes'];
            $cases[$driverName . ', session started by the caller'] = [$driver[0], 'no'];
        }

        return $cases;
    }

    /**
     * Flash data has to survive exactly one further request: it is readable in the request that set it and in the next
     * one, and is gone in the one after that.
     *
     * @param string $driver The driver the helper connects with
     * @param string $autostart Whether the library starts the session ("yes") or the caller does ("no")
     * @return void
     */
    #[DataProvider('sessionStartProvider')]
    #[TestDox('Flash data survives exactly one further request ($_dataName)')]
    public function testFlashData(string $driver, string $autostart): void
    {
        $this->driver = $driver;

        $payload = uniqid();

        $env = [
            'READ_ONLY' => 'no',
            'AUTOSTART_SESSION' => $autostart,
            'READ_FLASHDATA' => 'flashvar',
        ];

        // the request that sets it can also read it
        $process = $this->runHelper($env + ['SET_FLASHDATA' => 'flashvar:' . $payload]);
        $this->assertSame($payload, $this->readFlashData($process)['flashvar'] ?? null, 'Flash data was not readable in the request that set it.');

        // the next request still sees it
        $process = $this->runHelper($env);
        $this->assertSame($payload, $this->readFlashData($process)['flashvar'] ?? null, 'Flash data was not available in the next request.');

        // and the one after that does not
        $process = $this->runHelper($env);
        $this->assertNull($this->readFlashData($process)['flashvar'] ?? null, 'Flash data outlived the request it was supposed to be deleted in.');
    }

    /**
     * Flash data variables are counted one by one, so variables set in different requests have to expire in different
     * requests - a single shared counter would make the older one drag the newer one out with it.
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Flash data variables set in different requests expire in different requests ($_dataName)')]
    public function testFlashDataVariablesExpireIndependently(string $driver): void
    {
        $this->driver = $driver;

        $first = uniqid();
        $second = uniqid();
        $env = ['READ_ONLY' => 'no', 'AUTOSTART_SESSION' => 'yes', 'READ_FLASHDATA' => 'first,second'];

        // request 1 sets the first variable
        $this->runHelper($env + ['SET_FLASHDATA' => 'first:' . $first]);

        // request 2 sets the second one; the first is on its last request
        $flash = $this->readFlashData($this->runHelper($env + ['SET_FLASHDATA' => 'second:' . $second]));
        $this->assertSame($first, $flash['first'] ?? null, 'The variable set in the previous request was already gone.');
        $this->assertSame($second, $flash['second'] ?? null, 'The variable just set was not readable.');

        // request 3: the first has expired, the second is on its last request
        $flash = $this->readFlashData($this->runHelper($env));
        $this->assertNull($flash['first'] ?? null, 'The older flash data variable outlived its request.');
        $this->assertSame($second, $flash['second'] ?? null, 'The newer flash data variable expired together with the older one.');

        // request 4: both gone
        $flash = $this->readFlashData($this->runHelper($env));
        $this->assertNull($flash['first'] ?? null, 'The older flash data variable is still around.');
        $this->assertNull($flash['second'] ?? null, 'The newer flash data variable outlived its request.');
    }
}
