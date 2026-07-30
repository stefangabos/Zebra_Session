<?php

/**
 * Runs PHP in a process of its own and hands back what it did.
 *
 * Two kinds of test need this. Anything that ends the script - a deliberate die(), an uncaught exception, a
 * fatal error - would take the test runner with it in-process. And anything about session locking needs two
 * requests alive at the same time, since a lock can only be observed while something else is holding it.
 *
 *   ChildProcess::run($script, $env)     starts it, waits for it, returns what it did
 *   ChildProcess::start($script, $env)   starts it and hands back a handle to watch and kill
 *
 * Both take either the path of a PHP file or a snippet of code, plus the environment it runs with.
 */
class ChildProcess
{
    /**
     * The lines a child runs before a snippet it was given. A script given by path brings its own.
     *
     * @return  string
     */
    private static function preamble() {

        return '<?php' . PHP_EOL
            . 'require_once ' . var_export(dirname(dirname(__DIR__)) . '/Zebra_Session.php', true) . ';' . PHP_EOL;

    }

    /**
     * The PHP interpreter running this suite, so that a child is always the same version as its parent.
     *
     * PHP_BINARY is the full path to the running interpreter under the CLI SAPI, which is the only place
     * this suite runs. A child that could not be started is reported by proc_open() either way.
     *
     * @return  string
     */
    private static function interpreter() {

        return PHP_BINARY;

    }

    /**
     * Works out what to run, writing a temporary script when handed code rather than a path.
     *
     * @param   string  $script     the path of a PHP file, or the body of one
     *
     * @return  array<string, mixed>    "path" and whether it is "temporary"
     */
    private static function resolve($script) {

        if (strpos($script, PHP_EOL) === false && substr($script, -4) === '.php' && is_file($script)) {
            return ['path' => $script, 'temporary' => false];
        }

        $path = getTempPath('child') . '/child_' . md5($script) . '.php';

        // the marker at the end is how a test tells "ran to completion" from "was stopped part way"
        file_put_contents($path, self::preamble() . $script . PHP_EOL . 'echo "[REACHED THE END]";' . PHP_EOL);

        return ['path' => $path, 'temporary' => true];

    }

    /**
     * Starts a child and returns straight away.
     *
     * @param   string                  $script     the path of a PHP file, or the body of one
     * @param   array<string, string>   $env        added on top of the environment phpunit was started with
     *
     * @return  ChildProcessHandle
     */
    public static function start($script, $env = []) {

        $resolved = self::resolve($script);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            escapeshellarg(self::interpreter()) . ' ' . escapeshellarg($resolved['path']),
            $descriptors,
            $pipes,
            null,
            array_merge(getenv(), $env)
        );

        if (!is_resource($process)) {
            if ($resolved['temporary']) unlink($resolved['path']);
            throw new RuntimeException('Could not start a child PHP process');
        }

        // reading either pipe blocks until the child closes it, which for a child still running is never
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new ChildProcessHandle($process, $pipes, $resolved['temporary'] ? $resolved['path'] : null);

    }

    /**
     * Runs a child to completion and returns what became of it.
     *
     * @param   string                  $script     the path of a PHP file, or the body of one
     * @param   array<string, string>   $env        added on top of the environment phpunit was started with
     *
     * @return  array<string, mixed>    "status", "output" and "reached_the_end"
     */
    public static function run($script, $env = []) {

        $handle = self::start($script, $env);

        $handle->wait();

        return $handle->status();

    }
}
