<?php

/**
 * Mutation check - reverts one fix at a time and reports whether the test that claims to guard it fails.
 *
 * A green suite proves nothing until you have watched it go red.
 *
 *     php mutate.php
 *
 * Each entry reverts a fix by replacing "from" with "to", runs only the tests matching "filter", and puts
 * the library back afterwards. Every one of them should come out CAUGHT.
 *
 * Some tests legitimately pass in both directions and are deliberately absent - testAStringifyingConnection-
 * StillWorks guards against the fix going too far rather than against the bug. So are the three fixes
 * nothing outside the library can observe; RegressionTest's class docblock names them.
 *
 * This file is a development tool and is kept out of the package by .gitattributes.
 */

$library = __DIR__ . '/Zebra_Session.php';
$php     = getenv('PHP') ? getenv('PHP') : 'php';

$base = file_get_contents($library);

$mutations = [

    'GET_LOCK only 0 counted as failure' => [
        'filter' => 'testAStringifyingConnectionStillNoticesAMissingLock',
        'from'   => "if (\$result['num_rows'] !== 1 || (int)current(\$result['data']) !== 1) {\n                throw new Exception('Zebra_Session: Could not obtain session lock');",
        'to'     => "if (\$result['num_rows'] !== 1 || current(\$result['data']) === 0) {\n                throw new Exception('Zebra_Session: Could not obtain session lock');",
    ],

    'RELEASE_LOCK held by another connection' => [
        'filter' => 'testFailureToReleaseTheSessionLockIsReported',
        'from'   => "if (\$result['num_rows'] !== 1 || (int)current(\$result['data']) !== 1) {\n            throw new Exception('Zebra_Session: Could not release session lock');",
        'to'     => "if (\$result['num_rows'] !== 1 || current(\$result['data']) === false) {\n            throw new Exception('Zebra_Session: Could not release session lock');",
    ],

    'RELEASE_LOCK on a lock that vanished' => [
        'filter' => 'testAVanishedSessionLockIsReported',
        'from'   => "if (\$result['num_rows'] !== 1 || (int)current(\$result['data']) !== 1) {\n            throw new Exception('Zebra_Session: Could not release session lock');",
        'to'     => "if (\$result['num_rows'] !== 1 || current(\$result['data']) === 0) {\n            throw new Exception('Zebra_Session: Could not release session lock');",
    ],

    '#16 lock name shortened with sha1' => [
        'filter' => 'testLockNameFitsWithinMysqlsLimit',
        'from'   => "\$this->session_lock = 'session_' . sha1(\$session_id);",
        'to'     => "\$this->session_lock = 'session_' . \$session_id;",
    ],

    '#37 use_strict_mode left alone' => [
        'filter' => 'testTheConstructorLeavesTheSettingsItGaveUpOnAlone',
        'from'   => "ini_set('session.use_only_cookies', '1');",
        'to'     => "ini_set('session.use_only_cookies', '1');\n            ini_set('session.use_strict_mode', '1');",
    ],

    '258d701 stop() on an unstarted session' => [
        'filter' => 'testStopDoesNotComplainAboutAnUninitializedSession',
        'from'   => "        session_unset();\n        session_destroy();",
        'to'     => "        session_unset();\n        session_destroy();\n        session_destroy();",
    ],

    'a20702a get_active_sessions calls gc()' => [
        'filter' => 'testGetActiveSessions',
        'from'   => '$this->gc(0);',
        'to'     => '',
    ],

];

if (empty($mutations)) exit('nothing to check - fill in the list in ' . basename(__FILE__) . "\n");

$width = max(array_map('strlen', array_keys($mutations)));
$failed = 0;

foreach ($mutations as $label => $mutation) {

    // a pattern that does not match means the entry is stale, which is a result in itself
    if (strpos($base, $mutation['from']) === false) {
        printf("%-{$width}s  ?? pattern not found - the entry is stale\n", $label);
        $failed++;
        continue;
    }

    file_put_contents($library, str_replace($mutation['from'], $mutation['to'], $base));

    $output = [];
    exec(
        escapeshellarg($php) . ' vendor/bin/phpunit -c tests --filter ' . escapeshellarg($mutation['filter']) . ' 2>&1',
        $output
    );

    $result = implode(' ', $output);

    // phpunit reports the count only when something went wrong, so look for the failure markers instead
    $caught = strpos($result, 'FAILURES') !== false || strpos($result, 'ERRORS') !== false;

    // a filter that matched nothing is not a pass - the test being named probably got renamed
    if (strpos($result, 'No tests executed') !== false) {
        printf("%-{$width}s  ?? the filter matched no tests\n", $label);
        $failed++;
    } else {
        printf("%-{$width}s  %s\n", $label, $caught ? 'CAUGHT' : '*** NOT CAUGHT ***');
        if (!$caught) $failed++;
    }

    file_put_contents($library, $base);

}

// restore no matter what, in case a run above was interrupted
file_put_contents($library, $base);

echo "\n" . ($failed === 0
    ? "all fixes are guarded\n"
    : $failed . ' of ' . count($mutations) . " need attention - the fix is not protected by the test that names it\n");

exit($failed === 0 ? 0 : 1);
