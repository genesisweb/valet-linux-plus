#!/usr/bin/env php
<?php

use ConsoleComponents\Writer;
use Silly\Application;

require_once __DIR__.'/app.php';

/**
 * Run the application.
 */
try {
    /** @var Application $app */
    $app->run();
} catch (Exception $e) {
    Writer::error($e->getMessage());
}
