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
     * @return array<int,string>
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
     * Migrate ~/.valet directory to ~/.config/valet directory
     */
    public function migrateConfig(): void
    {
        $newHomePath = VALET_HOME_PATH;
        $oldHomePath = OLD_VALET_HOME_PATH;

        // Check if new config home already exists, then skip the process
        if ($this->files->isDir($newHomePath)) {
            return;
        }

        // Fetch FPM running process
        $fpmVersions = $this->getRunningFpmVersions($oldHomePath);

        // Stop running fpm services
        if (count($fpmVersions)) {
            foreach ($fpmVersions as $fpmVersion) {
                \Valet\Facades\PhpFpm::stop($fpmVersion);
            }
        }

        // Copy directory
        $this->files->copyDirectory($oldHomePath, $newHomePath);

        // Replace $oldHomePath to $newHomePath in Certificates, Valet.conf file
        $this->updateNginxConfFiles();

        // Update phpfpm's socket file path in config
        \Valet\Facades\PhpFpm::updateHomePath($oldHomePath, $newHomePath);

        // Start fpm services again
        if (count($fpmVersions)) {
            foreach ($fpmVersions as $fpmVersion) {
                \Valet\Facades\PhpFpm::restart($fpmVersion);
            }
        } else {
            \Valet\Facades\PhpFpm::restart();
        }

        \Valet\Facades\Nginx::restart();

        info('Valet home directory is migrated successfully! Please re-run your command');
        info(\sprintf('New home directory: %s', $newHomePath));
        info(\sprintf('NOTE: Please remove %s directory manually', $oldHomePath));
        exit;
    }

    private function updateNginxConfFiles(): void
    {
        $newHomePath = VALET_HOME_PATH;
        $oldHomePath = OLD_VALET_HOME_PATH;
        $nginxPath = $newHomePath.'/Nginx';

        $siteConfigs = $this->files->scandir($nginxPath);
        foreach ($siteConfigs as $siteConfig) {
            $filePath = \sprintf('%s/%s', $nginxPath, $siteConfig);
            $content = $this->files->get($filePath);
            $content = str_replace($oldHomePath, $newHomePath, $content);
            $this->files->put($filePath, $content);
        }

        $sitesAvailableConf = $this->files->get(Nginx::SITES_AVAILABLE_CONF);
        $sitesAvailableConf = str_replace($oldHomePath, $newHomePath, $sitesAvailableConf);
        $this->files->put(Nginx::SITES_AVAILABLE_CONF, $sitesAvailableConf);

        $nginxConfig = $this->files->get(Nginx::NGINX_CONF);
        $nginxConfig = str_replace($oldHomePath, $newHomePath, $nginxConfig);
        $this->files->put(Nginx::SITES_AVAILABLE_CONF, $nginxConfig);
    }

    private function getRunningFpmVersions(string $homePath): array
    {
        $runningVersions = [];

        $files = $this->files->scandir($homePath);
        foreach ($files as $file) {
            preg_match('/valet(\d)(\d)\.sock/', $file, $matches);
            if (count($matches) >= 2) {
                $runningVersions[] = \sprintf('%d.%d', $matches[1], $matches[2]);
            }
        }

        return $runningVersions;
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
