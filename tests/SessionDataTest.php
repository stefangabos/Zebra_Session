<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * What goes into a session comes back out of it unchanged, however big or however awkward - and a read-only session
 * never writes.
 */
#[TestDox('Session data')]
class SessionDataTest extends SessionTestCase
{
    /**
     * Test writing data to session:
     * - verify the data written in one request is available in another
     * - verify data written in read-only mode is not stored
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[TestDox('Data written in one request is visible in the next, and read-only writes are discarded ($_dataName)')]
    public function testSessionWrite(string $driver): void
    {
        $this->driver = $driver;

        // Open not read-only session and the data in another request.
        // Instead of closing session and opening it again, we keep it open and spawn a process to better reflect the real use case.
        $payloadNotToBeOverwritten = uniqid();
        $env = [
            'READ_ONLY' => 'no',
            'WRITE_DATA_TO_SESSION' => $payloadNotToBeOverwritten,
            'READ_DATA_FROM_SESSION' => 'yes',
        ];
        $writeToSession = $this->startHelper($env);
        $sessionLocked = $this->waitForOutput($writeToSession, '{"session_start":"' . self::$testingSid . '"}');
        $this->assertTrue($sessionLocked, 'Another process opened a locked session.');
        $writeToSession->stop();

        // Reopen the session and read the data.
        // We could do it directly, but a new Zebra_Session instance automatically registers itself as the handler, and it will mess up other tests.
        $env['READ_ONLY'] = 'no';
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        $readFromSession = $this->startHelper($env);
        $expectedOutput = json_encode([self::$testingSid => $payloadNotToBeOverwritten]);
        $payloadRead = $this->waitForOutput($readFromSession, $expectedOutput);
        $this->assertTrue($payloadRead, 'Saved value not read from session: '. $readFromSession->getOutput());
        $readFromSession->stop();

        // Now let's try to write data in RO session
        $payloadOverwriteTest = uniqid();
        $env['READ_ONLY'] = 'yes';
        $env['WRITE_DATA_TO_SESSION'] = $payloadOverwriteTest;
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        $writeToSession = $this->startHelper($env);
        // The script should output the new value, but it should not be saved
        $expectedOutput = json_encode([self::$testingSid => $payloadOverwriteTest]);
        $payloadRead = $this->waitForOutput($writeToSession, $expectedOutput);
        $this->assertTrue($payloadRead, 'Saved value not read from session: '. $writeToSession->getOutput());
        $writeToSession->stop();

        // Verify that session still holds the previous value
        $env['READ_ONLY'] = 'yes';
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        unset($env['WRITE_DATA_TO_SESSION']);
        $readFromSession = $this->startHelper($env);
        $expectedOutput = json_encode([self::$testingSid => $payloadNotToBeOverwritten]);
        $payloadRead = $this->waitForOutput($readFromSession, $expectedOutput);
        $this->assertTrue($payloadRead, "Value in session changed from '{$payloadNotToBeOverwritten}' to: " . $readFromSession->getOutput());
        $readFromSession->stop();
    }

    /**
     * Sessions are not always small - a shopping cart or a half filled in form adds up quickly - and the column they go
     * into used to be a `blob`, which stops at 65535 bytes. Going over that did not truncate the session quietly: the
     * write failed outright, so the request died and the visitor's session was lost.
     *
     * @see 32a436e, which widened the column to a mediumblob
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[Group('regression')]
    #[TestDox('A session larger than 64KB survives being stored ($_dataName)')]
    public function testLargeSessionDataSurvives(string $driver): void
    {
        $this->driver = $driver;

        // comfortably past what a blob column can hold
        $size = 200000;
        $expected = substr(str_repeat('abcdefghij', (int)ceil($size / 10)), 0, $size);

        $this->runHelper(['READ_ONLY' => 'no', 'WRITE_BIG_DATA' => (string)$size]);

        $process = $this->runHelper(['READ_ONLY' => 'no', 'READ_DATA_BASE64' => 'yes']);
        $stored = $this->readSessionDataBase64($process);

        $this->assertNotNull($stored, 'A large session was not stored at all.');
        $this->assertSame(strlen($expected), strlen((string)$stored), 'A large session came back a different size than it went in.');
        $this->assertSame($expected, $stored, 'A large session came back altered.');
    }

    /**
     * Session data is arbitrary bytes - PHP's serialization of it, which can hold null bytes, quotes, backslashes and
     * anything a user typed. It goes into the database through a prepared statement and comes back out of a blob column,
     * and the two drivers are handed their character set in different ways, so both need checking.
     *
     * The quotes and the backslash are also what keeps the library honest about binding its values rather than pasting
     * them into the query - the class ran on interpolated SQL until prepared statements went in.
     *
     * @see 33bc8bd and https://github.com/stefangabos/Zebra_Session/issues/20
     *
     * @param string $driver The driver the helper connects with
     * @return void
     */
    #[DataProvider('driverProvider')]
    #[Group('regression')]
    #[TestDox('Session data survives null bytes, quotes and multibyte characters ($_dataName)')]
    public function testAwkwardSessionDataSurvives(string $driver): void
    {
        $this->driver = $driver;

        $payload = "héllo—wörld 日本語 \x00 null byte, \"double\" and 'single' quotes, \\ backslash, \x01\x02\xff high bytes";

        $this->runHelper(['READ_ONLY' => 'no', 'WRITE_DATA_BASE64' => base64_encode($payload)]);

        $process = $this->runHelper(['READ_ONLY' => 'no', 'READ_DATA_BASE64' => 'yes']);
        $this->assertSame($payload, $this->readSessionDataBase64($process), 'Session data came back altered.');
    }
}
