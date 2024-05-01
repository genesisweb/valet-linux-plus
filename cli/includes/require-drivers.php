<?php
// DEVELOPER NOTE: Do not use latest php's functions in this file, as this file works with isolated versions
require_once './cli/Valet/Drivers/ValetDriver.php';

$drivers = scandir('./cli/Valet/Drivers');
if ($drivers !== false) {
    foreach ($drivers as $file) {
        $path = './cli/Valet/Drivers/'.$file;
        if (substr($file, 0, 1) !== '.' && !is_dir($path)) {
            require_once $path;
        }
    }
}
