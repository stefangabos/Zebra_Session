<?php

/**
 * Bootstrap file for PHPUnit tests.
 *
 * The composer autoloader brings in Zebra_Session itself, through the classmap in composer.json. The base class the
 * test classes extend is not part of the package, so it is required explicitly.
 */

require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/Support/SessionTestCase.php';
