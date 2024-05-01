<?php

namespace Valet\PackageManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class Pacman implements PackageManager
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var ServiceManager
     */
    public $serviceManager;
    /**
     * @var string
     */
    public $redisPackageName = 'redis';
    /**
     * @var string
     */
    public $mysqlPackageName = 'mysql';
    /**
     * @var string
     */
    public $mariaDBPackageName = 'mariadb';

    /**
     * @var array
     */
    const PHP_FPM_PATTERN_BY_VERSION = [];

    /**
     * Create a new Apt instance.
     */
    public function __construct(CommandLine $cli, ServiceManager $serviceManager)
    {
        $this->cli = $cli;
        $this->serviceManager = $serviceManager;
    }

    /**
     * Get array of installed packages.
     */
    public function packages(string $package): array
    {
        $query = "pacman -Qqs {$package}";

        return explode(PHP_EOL, $this->cli->run($query));
    }

    /**
     * Determine if the given package is installed.
     */
    public function installed(string $package): bool
    {
        return in_array($package, $this->packages($package));
    }

    /**
     * Ensure that the given package is installed.
     */
    public function ensureInstalled(string $package): void
    {
        if (!$this->installed($package)) {
            $this->installOrFail($package);
        }
    }

    /**
     * Install the given package and throw an exception on failure.
     */
    public function installOrFail(string $package): void
    {
        Writer::twoColumnDetail($package, 'Installing');

        $this->cli->run(
            trim('pacman --noconfirm --needed -S '.$package),
            function ($exitCode, $errorOutput) use ($package) {
                Writer::error(\sprintf('%s: %s', $exitCode, $errorOutput));

                throw new DomainException('Pacman was unable to install ['.$package.'].');
            }
        );
    }

    /**
     * Configure package manager on valet install.
     */
    public function setup(): void
    {
        // Nothing to do
    }

    /**
     * Determine if package manager is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $output = $this->cli->run('which pacman', function () {
                throw new DomainException('Pacman not available');
            });

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Determine php fpm package name.
     */
    public function getPhpFpmName(string $version): string
    {
        $pattern = !empty(self::PHP_FPM_PATTERN_BY_VERSION[$version])
            ? self::PHP_FPM_PATTERN_BY_VERSION[$version] : 'php{VERSION_WITHOUT_DOT}-fpm';
        $version = preg_replace('~[^\d]~', '', $version);

        return str_replace('{VERSION_WITHOUT_DOT}', $version, $pattern);
    }

    /**
     * Determine php extension pattern.
     */
    public function getPhpExtensionPrefix(string $version): string
    {
        $version = preg_replace('~[^\d]~', '', $version);
        $pattern = 'php{VERSION_WITHOUT_DOT}-';
        return str_replace('{VERSION_WITHOUT_DOT}', $version, $pattern);
    }

    /**
     * Restart dnsmasq in Ubuntu.
     */
    public function restartNetworkManager(): void
    {
        $this->serviceManager->restart('NetworkManager');
    }
}
