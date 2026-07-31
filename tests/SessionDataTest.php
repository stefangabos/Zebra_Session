<?php

require_once __DIR__ . '/bootstrap.php';

/**
 * What goes into a session comes back out of it unchanged, however big or however awkward - and a read-only session
 * never writes.
 */
class SessionDataTest extends SessionTestCase
{
    /**
     * Test writing data to session:
     * - verify the data written in one request is available in another
     * - verify data written in read-only mode is not stored
     *
     * @dataProvider drivers
     */
    public function testSessionWrite($driver) {
        $this->driver = $driver;

        // a writable session, held open while a second process works with it - what two real requests look like
        $payload_not_to_be_overwritten = uniqid();
        $env = [
            'READ_ONLY' => 'no',
            'WRITE_DATA_TO_SESSION' => $payload_not_to_be_overwritten,
            'READ_DATA_FROM_SESSION' => 'yes',
        ];
        $write_to_session = $this->startHelper($env);
        $session_locked = $write_to_session->waitForOutput('{"session_start":"' . TEST_SESSION_ID . '"}');
        $this->assertTrue($session_locked, 'Another process opened a locked session.');
        $write_to_session->stop();

        // read it back in a process of its own - a Zebra_Session registers itself as the session handler
        $env['READ_ONLY'] = 'no';
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        $read_from_session = $this->startHelper($env);
        $expected_output = json_encode([TEST_SESSION_ID => $payload_not_to_be_overwritten]);
        $payload_read = $read_from_session->waitForOutput($expected_output);
        $this->assertTrue($payload_read, 'Saved value not read from session: ' . $read_from_session->output());
        $read_from_session->stop();

        // Now let's try to write data in RO session
        $payload_overwrite_test = uniqid();
        $env['READ_ONLY'] = 'yes';
        $env['WRITE_DATA_TO_SESSION'] = $payload_overwrite_test;
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        $write_to_session = $this->startHelper($env);
        // The script should output the new value, but it should not be saved
        $expected_output = json_encode([TEST_SESSION_ID => $payload_overwrite_test]);
        $payload_read = $write_to_session->waitForOutput($expected_output);
        $this->assertTrue($payload_read, 'Saved value not read from session: ' . $write_to_session->output());
        $write_to_session->stop();

        // Verify that session still holds the previous value
        $env['READ_ONLY'] = 'yes';
        $env['READ_DATA_FROM_SESSION'] = 'yes';
        unset($env['WRITE_DATA_TO_SESSION']);
        $read_from_session = $this->startHelper($env);
        $expected_output = json_encode([TEST_SESSION_ID => $payload_not_to_be_overwritten]);
        $payload_read = $read_from_session->waitForOutput($expected_output);
        $this->assertTrue($payload_read, "Value in session changed from '{$payload_not_to_be_overwritten}' to: " . $read_from_session->output());
        $read_from_session->stop();
    }

    /**
     * Sessions are not always small - a shopping cart or a half filled in form adds up quickly - and the column they go
     * into used to be a `blob`, which stops at 65535 bytes. Going over that did not truncate the session quietly: the
     * write failed outright, so the request died and the visitor's session was lost.
     *
     * @see 32a436e, which widened the column to a mediumblob
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testLargeSessionDataSurvives($driver) {
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
     * The quotes and the backslash also cover the binding of values, rather than pasting them into the query.
     *
     * @see 33bc8bd and https://github.com/stefangabos/Zebra_Session/issues/20
     *
     * @dataProvider drivers
     * @group regression
     */
    public function testAwkwardSessionDataSurvives($driver) {
        $this->driver = $driver;

        $payload = "héllo—wörld 日本語 \x00 null byte, \"double\" and 'single' quotes, \\ backslash, \x01\x02\xff high bytes";

        $this->runHelper(['READ_ONLY' => 'no', 'WRITE_DATA_BASE64' => base64_encode($payload)]);

        $process = $this->runHelper(['READ_ONLY' => 'no', 'READ_DATA_BASE64' => 'yes']);
        $this->assertSame($payload, $this->readSessionDataBase64($process), 'Session data came back altered.');
    }
}
