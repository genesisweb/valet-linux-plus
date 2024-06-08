<?php

namespace Valet;

use Exception;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;

/**
 * Define constants.
 */
if (! defined('VALET_HOME_PATH')) {
    if (testing()) {
        define('VALET_HOME_PATH', __DIR__.'/../../tests/config/valet');
        define('OLD_VALET_HOME_PATH', __DIR__.'/../../tests/old-config/valet');

    } else {
        define('VALET_HOME_PATH', $_SERVER['HOME'].'/.config/valet');
        define('OLD_VALET_HOME_PATH', $_SERVER['HOME'].'/.valet');
    }
}

if (! defined('VALET_STATIC_PREFIX')) {
    define('VALET_STATIC_PREFIX', '41c270e4-5535-4daa-b23e-c269744c2f45');
}
define('VALET_LOOPBACK', '127.0.0.1');
define('VALET_ROOT_PATH', realpath(__DIR__.'/../../'));
define('VALET_SERVER_PATH', realpath(__DIR__.'/../../server.php'));
define('ISOLATED_PHP_VERSION', 'ISOLATED_PHP_VERSION');

/**
 * Return whether the app is in the testing environment.
 */
function testing(): bool
{
    return strpos($_SERVER['SCRIPT_NAME'], 'phpunit') !== false;
}

if (!function_exists('resolve')) {
    /**
     * Resolve the given class from the container.
     * @return mixed
     * @throws BindingResolutionException
     */
    function resolve(string $class)
    {
        return Container::getInstance()->make($class);
    }
}

/**
 * Swap the given class implementation in the container.
 * @param mixed  $instance
 */
function swap(string $class, $instance): void
{
    Container::getInstance()->instance($class, $instance);
}

if (!function_exists('retry')) {
    /**
     * Retry the given function N times.
     * @throws Exception
     * @return mixed
     */
    function retry(int $retries, callable $fn, int $sleep = 0)
    {
        beginning:
        try {
            return $fn();
        } catch (Exception $e) {
            if (!$retries) {
                throw $e;
            }

            $retries--;

            if ($sleep > 0) {
                usleep($sleep * 1000);
            }

            goto beginning;
        }
    }
}

if (!function_exists('tap')) {
    /**
     * Tap the given value.
     * @param mixed    $value
     * @return mixed
     */
    function tap($value, callable $callback)
    {
        $callback($value);

        return $value;
    }
}

/**
 * Get the user.
 */
function user(): string
{
    if (!isset($_SERVER['SUDO_USER'])) {
        return $_SERVER['USER'];
    }

    return $_SERVER['SUDO_USER'];
}

/**
 * Get the user's group.
 * @return string|false
 */
function group()
{
    if (!isset($_SERVER['SUDO_USER'])) {
        return exec('id -gn '.$_SERVER['USER']);
    }

    return exec('id -gn '.$_SERVER['SUDO_USER']);
}

/**
 * Search and replace using associative array.
 */
function strArrayReplace(array $searchAndReplace, string $subject): string
{
    return str_replace(array_keys($searchAndReplace), array_values($searchAndReplace), $subject);
}
