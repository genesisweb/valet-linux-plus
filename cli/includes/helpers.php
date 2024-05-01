<?php

namespace Valet;

use Exception;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Define constants.
 */
if (! defined('VALET_HOME_PATH')) {
    if (testing()) {
        define('VALET_HOME_PATH', __DIR__.'/../../tests/config/valet');
    } else {
        define('VALET_HOME_PATH', $_SERVER['HOME'].'/.config/valet');
    }
}
define('OLD_VALET_HOME_PATH', $_SERVER['HOME'].'/.valet');

if (! defined('VALET_STATIC_PREFIX')) {
    define('VALET_STATIC_PREFIX', '41c270e4-5535-4daa-b23e-c269744c2f45');
}
define('VALET_LOOPBACK', '127.0.0.1');
define('VALET_ROOT_PATH', realpath(__DIR__.'/../../')); //TODO: Check if it is in user
define('VALET_BIN_PATH', realpath(__DIR__.'/../../bin/')); //TODO: Check if it is in user
define('VALET_SERVER_PATH', realpath(__DIR__.'/../../server.php'));
define('ISOLATED_PHP_VERSION', 'ISOLATED_PHP_VERSION');

/**
 * Return whether the app is in the testing environment.
 */
function testing(): bool
{
    return strpos($_SERVER['SCRIPT_NAME'], 'phpunit') !== false;
}

/**
 * Set or get a global console writer.
 * @throws BindingResolutionException
 */
function writer(?OutputInterface $writer = null): ?OutputInterface
{
    $container = Container::getInstance();

    if (! $writer) {
        if (! $container->bound('writer')) {
            $container->instance('writer', new ConsoleOutput());
        }

        return $container->make('writer');
    }

    $container->instance('writer', $writer);

    return null;
}
/**
 * Output the given text to the console.
 */
function info(string $output): void
{
    output('<info>'.$output.'</info>');
}

/**
 * Output the given text to the console.
 */
function warning(string $output): void
{
    output('<fg=red>'.$output.'</>');
}

/**
 * Output a table to the console.
 */
function table(array $headers = [], array $rows = []): void
{
    $table = new Table(new ConsoleOutput());

    $table->setHeaders($headers)->setRows($rows);

    $table->render();
}

/**
 * Output the given text to the console.
 */
function output(string $output): void
{
    if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'testing') {
        return;
    }

    (new ConsoleOutput())->writeln($output);
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

if (!function_exists('endsWith')) {
    /**
     * Determine if a given string ends with a given substring.
     */
    function endsWith(string $haystack, string $needle): bool
    {
        return substr($haystack, -strlen($needle)) === $needle;
    }
}

if (!function_exists('startsWith')) {
    /**
     * Determine if a given string starts with a given substring.
     */
    function startsWith(string $haystack, string $needle): bool
    {
        return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
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
function user(): string // TODO: Validate user function
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
