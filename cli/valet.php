#!/usr/bin/env php
<?php

require_once __DIR__.'/app.php';

/**
 * Run the application.
 */
try {
    $app->run();
} catch (Exception $e) {
    warning($e->getMessage());
}
