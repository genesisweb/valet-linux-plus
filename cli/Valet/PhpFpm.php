<?php

namespace Valet;

use Exception;
use Tightenco\Collect\Support\Collection;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Exceptions\VersionException;
use Valet\Facades\DevTools as DevToolsFacade;
use Valet\Facades\Nginx as NginxFacade;

class PhpFpm
{
    protected $config;
    protected $pm;
    protected $sm;
    protected $cli;
    protected $files;
    protected $site;
    protected $nginx;

    const SUPPORTED_PHP_VERSIONS = [
        '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3',
    ];

    const COMMON_EXTENSIONS = [
        'cli', 'mysql', 'gd', 'zip', 'xml', 'curl', 'mbstring', 'pgsql', 'intl', 'posix',
    ];

    const FPM_CONFIG_FILE_NAME = 'valet.conf';

    /**
     * Create a new PHP FPM class instance.
     *
     * @param Configuration  $config
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     * @param Filesystem     $files
     * @param Site           $site
     * @param Nginx          $nginx
     */
    public function __construct(
        Configuration $config,
        PackageManager $pm,
        ServiceManager $sm,
        CommandLine $cli,
        Filesystem $files,
        Site $site,
        Nginx $nginx
    ) {
        $this->config = $config;
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
        $this->site = $site;
        $this->nginx = $nginx;
    }

    /**
     * Install and configure PHP FPM.
     * @throws VersionException
     */
    public function install(?string $version = null, bool $installExt = true): void
    {
        $version = $version ?: $this->getCurrentVersion();
        $version = $this->normalizePhpVersion($version);
        $this->validateVersion($version);

        $packageName = $this->pm->getPhpFpmName($version);
        if (!$this->pm->installed($packageName)) {
            $this->pm->ensureInstalled($packageName);
            if ($installExt) {
                $this->installExtensions($version);
            }
            $this->sm->enable($this->serviceName($version));
        }

        $this->files->ensureDirExists('/var/log', user());

        $this->installConfiguration($version);

        $this->restart($version);
    }

    /**
     * Uninstall PHP FPM valet config.
     */
    public function uninstall(): void
    {
        if ($this->files->exists($this->fpmConfigPath().'/'.self::FPM_CONFIG_FILE_NAME)) {
            $this->files->unlink($this->fpmConfigPath().'/'.self::FPM_CONFIG_FILE_NAME);
            $this->stop();
        }
    }

    /**
     * Change the php-fpm version.
     * @throws Exception
     */
    public function switchVersion(
        string $version = null,
        bool $updateCli = false,
        bool $ignoreExt = false,
        bool $ignoreUpdate = false
    ): void {
        $exception = null;

        $currentVersion = $this->getCurrentVersion();
        // Validate if in use
        $version = $this->normalizePhpVersion($version);

        try {
            $this->install($version, !$ignoreExt);
        } catch (Exception $e) {
            $version = $currentVersion;
            $exception = $e;
        }

        if ($this->sm->disabled($this->serviceName())) {
            $this->sm->enable($this->serviceName());
        }

        $this->config->set('php_version', $version);

        $this->stopIfUnused($currentVersion);

        $this->updateNginxConfigFiles($version);
        NginxFacade::restart();
        $this->status($version);
        if ($updateCli) {
            $this->cli->run("update-alternatives --set php /usr/bin/php$version");
            if (!$ignoreUpdate) {
                $this->handlePackageUpdate($version);
            }
        }

        if ($exception) {
            warning('Changing version failed');

            throw $exception;
        }
    }

    /**
     * Restart the PHP FPM process.
     */
    public function restart($version = null): void
    {
        $this->sm->restart($this->serviceName($version));
    }

    /**
     * Stop the PHP FPM process.
     */
    public function stop($version = null): void
    {
        $this->sm->stop($this->serviceName($version));
    }

    /**
     * PHP-FPM service status.
     */
    public function status($version = null): void
    {
        $this->sm->printStatus($this->serviceName($version));
    }

    /**
     * Isolate a given directory to use a specific version of PHP.
     * @throws VersionException
     */
    public function isolateDirectory(string $directory, string $version, bool $secure = false): void
    {
        $site = $this->site->getSiteUrl($directory);

        $version = $this->normalizePhpVersion($version);
        $this->validateVersion($version);

        $fpmName = $this->pm->getPhpFpmName($version);
        if (!$this->pm->installed($fpmName)) {
            $this->install($version);
        }

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "74"

        $this->site->isolate($site, $version, $secure);

        if ($oldCustomPhpVersion) {
            $this->stopIfUnused($oldCustomPhpVersion);
        }
        $this->restart($version);
        NginxFacade::restart();

        info(sprintf('The site [%s] is now using %s.', $site, $version));
    }

    /**
     * Remove PHP version isolation for a given directory.
     */
    public function unIsolateDirectory(string $directory): void
    {
        $site = $this->site->getSiteUrl($directory);

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "74"

        $this->site->removeIsolation($site);
        if ($oldCustomPhpVersion) {
            $this->stopIfUnused($oldCustomPhpVersion);
        }
        NginxFacade::restart();

        info(sprintf('The site [%s] is now using the default PHP version.', $site));
    }

    /**
     * List isolated directories with version.
     */
    public function isolatedDirectories(): Collection
    {
        return NginxFacade::configuredSites()->filter(function ($item) {
            return strpos($this->files->get(VALET_HOME_PATH.'/Nginx/'.$item), ISOLATED_PHP_VERSION) !== false;
        })->map(function ($item) {
            return ['url' => $item, 'version' => $this->normalizePhpVersion($this->site->customPhpVersion($item))];
        });
    }

    /**
     * Get FPM socket file name for a given PHP version.
     */
    public function socketFileName(string $version = null): string
    {
        if (!$version) {
            $version = $this->getCurrentVersion();
        }
        $version = preg_replace('~[^\d]~', '', $version);

        return "valet{$version}.sock";
    }

    /**
     * Normalize inputs (php-x.x, php@x.x, phpx.x, phpxx) to version (x.x).
     */
    public function normalizePhpVersion($version): string
    {
        return substr(preg_replace('/(?:php@?)?([0-9+])(?:.)?([0-9+])/i', '$1.$2', (string) $version), 0, 3);
    }

    /**
     * Get installed PHP version.
     */
    public function getCurrentVersion(): string
    {
        return $this->config->get('php_version', $this->getDefaultVersion());
    }

    /**
     * Get executable php path.
     * @return false|string
     */
    public function getPhpExecutablePath(string $version = null)
    {
        if (!$version) {
            return DevToolsFacade::getBin('php');
        }

        $version = $this->normalizePhpVersion($version);

        return DevToolsFacade::getBin('php'.$version);
    }

    public function fpmSocketFile(string $version): string
    {
        return VALET_HOME_PATH.'/'.$this->socketFileName($version);
    }

    /**
     * Stop a given PHP version, if that specific version isn't being used globally or by any sites.
     */
    private function stopIfUnused(string $version): void
    {
        $version = $this->normalizePhpVersion($version);

        if (!in_array($version, $this->utilizedPhpVersions())) {
            $this->stop($version);
        }
    }

    /**
     * Determine php service name.
     */
    private function serviceName(string $version = null): string
    {
        if (!$version) {
            $version = $this->getCurrentVersion();
        }

        return $this->pm->getPhpFpmName($version);
    }

    private function updateNginxConfigFiles(string $version): void
    {
        //Action 1: Update all separate secured versions
        NginxFacade::configuredSites()->map(function ($file) use ($version) {
            $content = $this->files->get(VALET_HOME_PATH.'/Nginx/'.$file);
            if (!$content) {
                return;
            }
            if (strpos($content, '# '.ISOLATED_PHP_VERSION) !== false) {
                return;
            }
            preg_match_all('/unix:(.*?.sock)/m', $content, $matchCount);
            if (!count($matchCount)) {
                return;
            }
            $content = preg_replace(
                '/unix:(.*?.sock)/m',
                'unix:'.VALET_HOME_PATH.'/'.$this->socketFileName($version),
                $content
            );
            $this->files->put(VALET_HOME_PATH.'/Nginx/'.$file, $content);
        });

        //Action 2: Update NGINX valet.conf for php socket version.
        NginxFacade::installServer($version);
    }

    private function installExtensions(string $version): void
    {
        $extArray = [];
        $extensionPrefix = $this->getExtensionPrefix($version);
        foreach (self::COMMON_EXTENSIONS as $ext) {
            $extArray[] = "{$extensionPrefix}-{$ext}";
        }
        $this->pm->ensureInstalled(implode(' ', $extArray));
    }

    /**
     * Update the PHP FPM configuration to use the current user.
     */
    private function installConfiguration(string $version): void
    {
        $contents = $this->files->get(__DIR__.'/../../stubs/fpm.conf');
        $contents = strArrayReplace([
            'VALET_USER'            => user(),
            'VALET_GROUP'           => group(),
            'VALET_FPM_SOCKET_FILE' => $this->fpmSocketFile($version),
        ], $contents);

        $this->files->putAsUser($this->fpmConfigPath($version).'/'.self::FPM_CONFIG_FILE_NAME, $contents);
    }

    /**
     * Get a list including the global PHP version and all PHP versions currently serving "isolated sites" (sites with
     * custom Nginx configs pointing them to a specific PHP version).
     */
    private function utilizedPhpVersions(): array
    {
        $fpmSockFiles = collect(self::SUPPORTED_PHP_VERSIONS)->map(function ($version) {
            return $this->socketFileName($this->normalizePhpVersion($version));
        })->unique();

        $versions = NginxFacade::configuredSites()->map(function ($file) use ($fpmSockFiles) {
            $content = $this->files->get(VALET_HOME_PATH.'/Nginx/'.$file);

            // Get the normalized PHP version for this config file, if it's defined
            foreach ($fpmSockFiles as $sock) {
                if (strpos($content, $sock) !== false) {
                    // Extract the PHP version number from a custom .sock path and normalize it to, e.g., "php@7.4"
                    return $this->normalizePhpVersion(str_replace(['valet', '.sock'], '', $sock));
                }
            }
        })->filter()->unique()->values()->toArray();

        // Adding Default version in utilized versions list.
        if (!in_array($this->getCurrentVersion(), $versions)) {
            $versions[] = $this->getCurrentVersion();
        }

        return $versions;
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     */
    private function fpmConfigPath(string $version = null): string
    {
        $version = $version ?: $this->getCurrentVersion();
        $versionWithoutDot = preg_replace('~[^\d]~', '', $version);

        return collect([
            '/etc/php/'.$version.'/fpm/pool.d', // Ubuntu
            '/etc/php'.$version.'/fpm/pool.d', // Ubuntu
            '/etc/php'.$version.'/php-fpm.d', // Manjaro
            '/etc/php'.$versionWithoutDot.'/php-fpm.d', // ArchLinux
            '/etc/php7/fpm/php-fpm.d', // openSUSE PHP7
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
            '/etc/php-fpm.d', // Fedora
            '/etc/php/php-fpm.d', // Arch
        ])->first(function ($path) {
            return is_dir($path);
        }, function () {
            throw new \DomainException('Unable to determine PHP-FPM configuration folder.');
        });
    }

    /**
     * Validate PHP version.
     * @throws VersionException
     */
    private function validateVersion(string $version): void
    {
        if (!in_array($version, self::SUPPORTED_PHP_VERSIONS)) {
            throw new VersionException(
                "Invalid version [$version] used. Supported versions are :".implode(self::SUPPORTED_PHP_VERSIONS)
            );
        }
    }

    /**
     * Get installed PHP version.
     */
    private function getDefaultVersion(): string
    {
        return $this->normalizePhpVersion(PHP_VERSION);
    }

    private function getExtensionPrefix($version = null): string
    {
        $version = $version ?: $this->getCurrentVersion();
        return $this->pm->getPhpExtensionPrefix($version);
    }

    private function handlePackageUpdate($version): void
    {
        $installedPhpVersion = $this->config->get('installed_php_version');
        if ($installedPhpVersion && $installedPhpVersion >= $version) {
            if (is_dir(__DIR__.'/../../../vendor')) {
                // Local vendor
                $this->cli->runAsUser('composer update');
            } else {
                // Global vendor
                $this->cli->runAsUser('composer global require genesisweb/valet-linux-plus:'.VALET_VERSION.' -W');
            }
            $this->config->set('installed_php_version', $version);
        }
    }
}
