<?php

namespace Valet;

use ConsoleComponents\Writer;
use DomainException;
use Exception;
use Illuminate\Container\Container;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\Configuration as ConfigurationFacade;
use Valet\Facades\Nginx as NginxFacade;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Facades\Request as RequestFacade;
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
    public CommandLine $cli;
    public Filesystem $files;
    private string $valetBin = '/usr/local/bin/valet';
    private string $phpBin = '/usr/local/bin/php';
    private string $github = 'https://api.github.com/repos/genesisweb/valet-linux-plus/releases/latest';

    /**
     * Create a new Valet instance.
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
        $this->cli->run('ln -snf ' . realpath(VALET_ROOT_PATH . '/valet') . ' ' . $this->valetBin);
    }

    /**
     * Symlink the Valet Bash script into the user's local bin.
     */
    public function symlinkPhpToUsersBin(): void
    {
        $fallbackBin = '/usr/bin/php';
        $phpBin = $_SERVER['_'] ?? $fallbackBin;
        $phpBin = $this->files->realpath($phpBin);
        if ($phpBin !== VALET_ROOT_PATH . 'php') {
            ConfigurationFacade::set('fallback_binary', $phpBin);
        } else {
            ConfigurationFacade::set('fallback_binary', $fallbackBin);
        }

        $this->cli->run('ln -snf ' . realpath(VALET_ROOT_PATH . '/php') . ' ' . $this->phpBin);
    }

    /**
     * Unlink the Valet Bash script from the user's local bin
     * and the sudoers.d entry.
     */
    public function uninstall(): void
    {
        $this->files->unlink($this->valetBin);
        $this->files->unlink($this->phpBin);
    }

    /**
     * Get the paths to all the Valet extensions.
     * @return array<int,string>
     */
    public function extensions(): array
    {
        if (!$this->files->isDir(VALET_HOME_PATH . '/Extensions')) {
            return [];
        }

        return collect($this->files->scandir(VALET_HOME_PATH . '/Extensions'))
                    ->reject(function ($file) {
                        return $this->files->isDir($file);
                    })
                    ->map(function ($file) {
                        return VALET_HOME_PATH . '/Extensions/' . $file;
                    })
                    ->values()->all();
    }

    /**
     * Determine if this is the latest version of Valet.
     * @throws Exception
     */
    public function onLatestVersion(string $currentVersion): bool
    {
        $response = RequestFacade::get($this->github)->send();

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
        $response = RequestFacade::get($this->github)->send();

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
                PhpFpmFacade::stop($fpmVersion);
            }
        }

        // Copy directory
        $this->files->copyDirectory($oldHomePath, $newHomePath);

        // Replace $oldHomePath to $newHomePath in Certificates, Valet.conf file
        $this->updateNginxConfFiles();

        // Update phpfpm's socket file path in config
        PhpFpmFacade::updateHomePath($oldHomePath, $newHomePath);

        // Start fpm services again
        if (count($fpmVersions)) {
            foreach ($fpmVersions as $fpmVersion) {
                PhpFpmFacade::restart($fpmVersion);
            }
        } else {
            PhpFpmFacade::restart();
        }

        NginxFacade::restart();

        Writer::info('Valet home directory is migrated successfully! Please re-run your command');
        Writer::info(\sprintf('New home directory: %s', $newHomePath));
        Writer::info(\sprintf('Please remove %s directory manually', $oldHomePath));
        exit;
    }

    private function updateNginxConfFiles(): void
    {
        $newHomePath = VALET_HOME_PATH;
        $oldHomePath = OLD_VALET_HOME_PATH;
        $nginxPath = $newHomePath . '/Nginx';

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
        $this->files->put(Nginx::NGINX_CONF, $nginxConfig);
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
