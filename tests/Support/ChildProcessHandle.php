<?php

/**
 * A child that is still running, or has just finished.
 *
 * Everything that has to remember something about one particular child lives here, so that the entry points
 * on ChildProcess stay static and short.
 */
class ChildProcessHandle
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private $pipes;

    /** @var string|null the temporary script to remove once this is done with, if there is one */
    private $temporary_script;

    /**
     * Everything the child has printed so far.
     *
     * A stream hands back only what has arrived since it was last read, so each read is appended here and
     * the searches run against the whole of it. Looking at a single read in isolation would miss a marker
     * that arrived split across two of them.
     *
     * @var string
     */
    private $output = '';

    /** @var int|null */
    private $exit_code = null;

    /**
     * @param   resource                $process
     * @param   array<int, resource>    $pipes
     * @param   string|null             $temporary_script
     */
    public function __construct($process, $pipes, $temporary_script) {
        $this->process = $process;
        $this->pipes = $pipes;
        $this->temporary_script = $temporary_script;
    }

    /**
     * Reads whatever has arrived since the last time and adds it to what was already there.
     *
     * @return  string  everything printed so far, on either stream
     */
    public function output() {

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                $chunk = stream_get_contents($pipe);
                if ($chunk !== false && $chunk !== '') $this->output .= $chunk;
            }
        }

        return $this->output;

    }

    /**
     * Waits for a string to turn up in the child's output.
     *
     * @param   string  $needle                 what to wait for
     * @param   float   $timeout_in_seconds     how long to wait before giving up
     *
     * @return  bool                            false when it never arrived
     */
    public function waitForOutput($needle, $timeout_in_seconds = 5) {

        // microtime() rather than time(), which only advances on whole-second boundaries and would let a
        // one second timeout expire after a couple of milliseconds
        $start = microtime(true);

        while (microtime(true) - $start < $timeout_in_seconds) {

            if (strpos($this->output(), $needle) !== false) return true;

            // short enough not to be felt, long enough not to peg a core
            usleep(50000);

        }

        return false;

    }

    /**
     * Whether the child is still running.
     *
     * Every look at the child's status goes through here. proc_get_status() reports a real exit code exactly
     * once - on the first call after the child has ended - and answers -1 from then on, so the first call to
     * see it gone is the one that keeps the number.
     *
     * Impure - a child that was running a moment ago may not be now, which is what every wait loop here
     * depends on.
     *
     * @phpstan-impure
     *
     * @return  bool
     */
    public function isRunning() {

        if (!is_resource($this->process)) return false;

        $status = proc_get_status($this->process);

        if (!$status['running'] && $this->exit_code === null && $status['exitcode'] !== -1) {
            $this->exit_code = $status['exitcode'];
        }

        return $status['running'];

    }

    /**
     * Waits for the child to finish on its own.
     *
     * @param   float   $timeout_in_seconds     how long to wait before killing it
     *
     * @return  int                             the exit code
     */
    public function wait($timeout_in_seconds = 30) {

        $start = microtime(true);

        while ($this->isRunning() && microtime(true) - $start < $timeout_in_seconds) {
            // keep draining: a child that fills the pipe buffer blocks on its own write and never exits
            $this->output();
            usleep(20000);
        }

        return $this->close();

    }

    /**
     * Asks the child to stop, and insists if it does not.
     *
     * @param   float   $grace_in_seconds   how long it is given to go quietly
     *
     * @return  int                         the exit code
     */
    public function stop($grace_in_seconds = 1) {

        if ($this->isRunning()) {

            // 15 and 9 rather than SIGTERM and SIGKILL, which come from ext-pcntl
            proc_terminate($this->process, 15);

            $start = microtime(true);

            while ($this->isRunning() && microtime(true) - $start < $grace_in_seconds) usleep(20000);

            if ($this->isRunning()) proc_terminate($this->process, 9);

        }

        return $this->close();

    }

    /**
     * Kills the child outright, so that nothing it registered gets to run - no shutdown function, no session
     * closed on the way out. Which is what some of these tests are about.
     *
     * @return  int     the exit code
     */
    public function kill() {

        if ($this->isRunning()) proc_terminate($this->process, 9);

        return $this->close();

    }

    /**
     * What became of the child.
     *
     * @return  array<string, mixed>    "status", "output" and "reached_the_end"
     */
    public function status() {

        return [
            'status'            => $this->exit_code,
            'output'            => $this->output(),
            'reached_the_end'   => strpos($this->output(), '[REACHED THE END]') !== false,
        ];

    }

    /**
     * The exit code, once the child has finished.
     *
     * @return  int|null
     */
    public function exitCode() {

        return $this->exit_code;

    }

    /**
     * Drains what is left, closes everything and settles on the exit code.
     *
     * @return  int
     */
    private function close() {

        if (!is_resource($this->process)) return $this->exit_code === null ? -1 : $this->exit_code;

        // last read before the pipes go, and a chance for isRunning() to catch the exit code
        $this->output();
        $this->isRunning();

        foreach ($this->pipes as $pipe) if (is_resource($pipe)) fclose($pipe);

        // proc_close() reports the exit status only when it is the one that reaped the child; in any loop
        // that waited, isRunning() got there first and holds the real number
        $closed = proc_close($this->process);

        if ($this->exit_code === null) $this->exit_code = $closed;

        if ($this->temporary_script !== null && is_file($this->temporary_script)) {
            unlink($this->temporary_script);
            $this->temporary_script = null;
        }

        return $this->exit_code;

    }
}
