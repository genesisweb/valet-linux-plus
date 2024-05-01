<?php

namespace Valet;

use DomainException;
use Exception;
use Httpful\Request;
use Illuminate\Container\Container;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\PackageManagers\Apt;
use Valet\PackageManagers\Dnf;
use Valet\PackageManagers\Eopkg;
use Valet\PackageManagers\PackageKit;
use Valet\PackageManagers\Pacman;
use Valet\PackageManagers\Yum;
use Valet\ServiceManagers\LinuxService;
use Valet\ServiceManagers\Systemd;

class Valet
{
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var Filesystem
     */
    public $files;

    /**
     * @var string
     */
    private $valetBin = '/usr/local/bin/valet';
    /**
     * @var string
     */
    private $sudoers = '/etc/sudoers.d/valet';
    /**
     * @var string
     */
    private $github = 'https://api.github.com/repos/genesisweb/valet-linux-plus/releases/latest';

    /**
     * Create a new Valet instance.
     *
     * @param CommandLine $cli
     * @param Filesystem  $files
     */
    public function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Symlink the Valet Bash script into the user's local bin.
     */
    public function symlinkToUsersBin(): void
    {
        $this->cli->run('ln -snf '.realpath(__DIR__.'/../../valet').' '.$this->valetBin);
    }

    /**
     * Unlink the Valet Bash script from the user's local bin
     * and the sudoers.d entry.
     */
    public function uninstall(): void
    {
        $this->files->unlink($this->valetBin);
        $this->files->unlink($this->sudoers);
    }

    /**
     * Get the paths to all the Valet extensions.
     */
    public function extensions(): array
    {
        if (!$this->files->isDir(VALET_HOME_PATH.'/Extensions')) {
            return [];
        }

        return collect($this->files->scandir(VALET_HOME_PATH.'/Extensions'))
                    ->reject(function ($file) {
                        return is_dir($file);
                    })
                    ->map(function ($file) {
                        return VALET_HOME_PATH.'/Extensions/'.$file;
                    })
                    ->values()->all();
    }

    /**
     * Determine if this is the latest version of Valet.
     * @throws Exception
     */
    public function onLatestVersion(string $currentVersion): bool
    {
        $response = Request::get($this->github)->send();
        $currentVersion = str_replace('v', '', $currentVersion);
        $latestVersion = isset($response->body->tag_name) ? trim($response->body->tag_name) : 'v1.0.0';
        $latestVersion = str_replace('v', '', $latestVersion);

        return version_compare($currentVersion, $latestVersion, '>=');
    }

    /**
     * Retrieve the latest version of Valet Linux Plus.
     * @throws Exception
     * @return string|bool
     */
    public function getLatestVersion()
    {
        $response = Request::get($this->github)->send();

        return isset($response->body->tag_name) ? trim($response->body->tag_name) : false;
    }

    /**
     * Determine current environment.
     */
    public function environmentSetup(): void
    {
        $this->serviceManagerSetup();
        $this->packageManagerSetup();
    }

    /**
     * Configure package manager.
     */
    private function packageManagerSetup(): void
    {
        Container::getInstance()->bind(PackageManager::class, $this->getAvailablePackageManager());
    }

    /**
     * Determine the first available package manager.
     * @return class-string
     */
    private function getAvailablePackageManager(): string
    {
        return collect([
            Apt::class,
            Dnf::class,
            Pacman::class,
            Yum::class,
            PackageKit::class,
            Eopkg::class,
        ])->first(function ($pm) {
            return resolve($pm)->isAvailable();
        }, function () {
            throw new DomainException('No compatible package manager found.');
        });
    }

    /**
     * Configure service manager.
     */
    private function serviceManagerSetup(): void
    {
        Container::getInstance()->bind(ServiceManager::class, $this->getAvailableServiceManager());
    }

    /**
     * Determine the first available service manager.
     * @return class-string
     */
    private function getAvailableServiceManager(): string
    {
        return collect([
            Systemd::class,
            LinuxService::class,
        ])->first(function ($pm) {
            return resolve($pm)->isAvailable();
        }, function () {
            throw new DomainException('No compatible service manager found.');
        });
    }
}
