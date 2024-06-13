<?php

/**
 * Check the system's compatibility with Valet.
 */
$inTestingEnvironment = strpos($_SERVER['SCRIPT_NAME'], 'phpunit') !== false;

if (PHP_OS != 'Linux' && !$inTestingEnvironment) {
    echo 'Valet only supports Linux.'.PHP_EOL;

    exit(1);
}

if (version_compare(PHP_VERSION, '8.2', '<')) {
    echo 'Valet requires PHP 8.2 or later.';

    exit(1);
}
