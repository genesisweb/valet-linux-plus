<?php

namespace Valet;

use Exception;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

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
        '7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2'
    ];

    const COMMON_EXTENSIONS = [
        'common', 'cli', 'mysql', 'gd', 'zip', 'xml', 'curl', 'mbstring', 'pgsql', 'mongodb', 'intl'
    ];

    const FPM_CONFIG_FILE_NAME = 'valet.conf';

    /**
     * Create a new PHP FPM class instance.
     * @param Configuration $config
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine $cli
     * @param Filesystem $files
     * @param Site $site
     * @param Nginx $nginx
     *
     * @return void
     */
    public function __construct(
        Configuration  $config,
        PackageManager $pm,
        ServiceManager $sm,
        CommandLine    $cli,
        Filesystem     $files,
        Site           $site,
        Nginx          $nginx
    )
    {
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
     * @param string|null $version
     * @param bool $installExt
     * @return void
     * @throws Exception
     */
    public function install(string $version = null, bool $installExt = true)
    {
        $version = $version ?: $this->getCurrentVersion();
        $version = $this->normalizePhpVersion($version);
        $this->validateVersion($version);

        $extensionPrefix = $this->getExtensionPrefix($version);
        if ($this->pm->installed("{$extensionPrefix}-fpm")) {
            $this->pm->ensureInstalled("{$extensionPrefix}-fpm");
            if ($installExt) {
                $this->installExtensions($version);
            }
            $this->sm->enable($this->serviceName($version));
        }

        $this->files->ensureDirExists('/var/log', user());

        $this->installConfiguration($version);
    }

    /**
     * Uninstall PHP FPM valet config.
     *
     * @return void
     */
    public function uninstall()
    {
        if ($this->files->exists($this->fpmConfigPath() . '/' . self::FPM_CONFIG_FILE_NAME)) {
            $this->files->unlink($this->fpmConfigPath() . '/' . self::FPM_CONFIG_FILE_NAME);
            $this->stop();
        }
    }

    /**
     * Change the php-fpm version.
     *
     * @param string|float|int $version
     * @param bool|null $updateCli
     * @param bool|null $installExt
     * @return void
     * @throws Exception
     *
     */
    public function switchVersion($version = null, bool $updateCli = false, bool $installExt = false)
    {
        $exception = null;

        $currentVersion = $this->getCurrentVersion();
        // Validate if in use
        $version = $this->normalizePhpVersion($version);
        try {
            $this->install($version, $installExt);
        } catch (\Exception $e) {
            $version = $currentVersion;
            $exception = $e;
        }

        if ($this->sm->disabled($this->serviceName())) {
            $this->sm->enable($this->serviceName());
        }

        $this->config->set('php_version', $version);

        $this->stopIfUnused($currentVersion);

        // TODO: Switch php socket file in nginx configuration files.

        if ($updateCli) {
            $this->cli->run("update-alternatives --set php /usr/bin/php{$version}");
        }

        if ($exception) {
            warning('Changing version failed');
            throw $exception;
        }
    }

    /**
     * Restart the PHP FPM process.
     *
     * @return void
     */
    public function restart($version = null)
    {
        $this->sm->restart($this->serviceName($version));
    }

    /**
     * Stop the PHP FPM process.
     *
     * @return void
     */
    public function stop($version = null)
    {
        $this->sm->stop($this->serviceName($version));
    }

    /**
     * PHP-FPM service status.
     *
     * @return void
     */
    public function status($version = null)
    {
        $this->sm->status($this->serviceName($version));
    }

    /**
     * Stop a given PHP version, if that specific version isn't being used globally or by any sites.
     * @param string|float|null $version
     * @return void
     */
    public function stopIfUnused($version = null)
    {
        if (!$version) {
            return;
        }

        $version = $this->normalizePhpVersion($version);

        if (!in_array($version, $this->utilizedPhpVersions())) {
            $this->stop($version);
        }
    }

    /**
     * Isolate a given directory to use a specific version of PHP.
     *
     * @param string $directory
     * @param string $version
     * @param bool   $secure
     *
     * @return void
     * @throws Exception
     */
    public function isolateDirectory($directory, $version, $secure = false)
    {
        $site = $this->site->getSiteUrl($directory);

        $version = $this->normalizePhpVersion($version);
        $this->validateVersion($version);

        $extensionPrefix = $this->getExtensionPrefix($version);
        if (!$this->pm->installed("{$extensionPrefix}-fpm")) {
            $this->install($version);
        }

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "74"

        $this->site->isolate($site, $version, $secure);

        if ($oldCustomPhpVersion) {
            $this->stopIfUnused($oldCustomPhpVersion);
        }
        $this->restart($version);
        $this->nginx->restart();

        info(sprintf('The site [%s] is now using %s.', $site, $version));
    }

    /**
     * Remove PHP version isolation for a given directory.
     *
     * @param string $directory
     *
     * @return void
     */
    public function unIsolateDirectory(string $directory)
    {
        $site = $this->site->getSiteUrl($directory);

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "74"

        $this->site->removeIsolation($site);
        if ($oldCustomPhpVersion) {
            $this->stopIfUnused($oldCustomPhpVersion);
        }
        $this->nginx->restart();

        info(sprintf('The site [%s] is now using the default PHP version.', $site));
    }

    public function isolatedDirectories()
    {
        return $this->nginx->configuredSites()->filter(function ($item) {
            return strpos($this->files->get(VALET_HOME_PATH.'/Nginx/'.$item), ISOLATED_PHP_VERSION) !== false;
        })->map(function ($item) {
            return ['url' => $item, 'version' => $this->normalizePhpVersion($this->site->customPhpVersion($item))];
        });
    }

    protected function installExtensions($version)
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
     *
     * @param string|float $version
     * @return void
     */
    protected function installConfiguration($version)
    {
        $contents = $this->files->get(__DIR__ . '/../stubs/fpm.conf');

        $this->files->putAsUser(
            $this->fpmConfigPath() . '/' . self::FPM_CONFIG_FILE_NAME,
            str_array_replace([
                'VALET_USER' => user(),
                'VALET_GROUP' => group(),
                'VALET_FPM_SOCKET_FILE' => VALET_HOME_PATH. '/'.$this->socketFileName($version),
            ], $contents)
        );
    }

    /**
     * Get a list including the global PHP version and all PHP versions currently serving "isolated sites" (sites with
     * custom Nginx configs pointing them to a specific PHP version).
     *
     * @return array
     */
    public function utilizedPhpVersions()
    {
        $fpmSockFiles = collect(self::SUPPORTED_PHP_VERSIONS)->map(function ($version) {
            return $this->socketFileName($this->normalizePhpVersion($version));
        })->unique();

        $versions = $this->nginx->configuredSites()->map(function ($file) use ($fpmSockFiles) {
            $content = $this->files->get(VALET_HOME_PATH . '/Nginx/' . $file);

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
     * Get FPM socket file name for a given PHP version.
     * @param string|float|null $version
     * @return string
     */
    protected function socketFileName($version = null)
    {
        if (!$version) {
            $version = $this->getCurrentVersion();
        }
        $version = preg_replace('~[^\d]~', '', $version);

        return "valet{$version}.sock";
    }

    /**
     * Determine php service name.
     *
     * @return string
     */
    protected function serviceName($version = null)
    {
        if (!$version) {
            $version = $this->getCurrentVersion();
        }
        return $this->getExtensionPrefix($version) . '-fpm';
    }

    /**
     * Normalize inputs (php-x.x, php@x.x, phpx.x, phpxx) to version (x.x)
     */
    protected function normalizePhpVersion($version)
    {
        return substr(preg_replace('/(?:php@?)?([0-9+])(?:.)?([0-9+])/i', '$1.$2', (string)$version), 0, 3);
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    protected function fpmConfigPath($version = null)
    {
        $version = $version ?: $this->getCurrentVersion();

        return collect([
            '/etc/php/' . $version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $version . '/php-fpm.d', // Manjaro
            '/etc/php-fpm.d', // Fedora
            '/etc/php/php-fpm.d', // Arch
            '/etc/php7/fpm/php-fpm.d', // openSUSE PHP7
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
        ])->first(function ($path) {
            return is_dir($path);
        }, function () {
            throw new \DomainException('Unable to determine PHP-FPM configuration folder.');
        });
    }

    /**
     * Validate PHP version
     * @return void
     * @throws Exception
     */
    protected function validateVersion($version)
    {
        if (!in_array($version, self::SUPPORTED_PHP_VERSIONS)) {
            throw new Exception(
                "Invalid version [$version] used. Supported versions are :" . implode(self::SUPPORTED_PHP_VERSIONS)
            );
        }
    }

    /**
     * Get installed PHP version.
     * @return string
     */
    protected function getCurrentVersion()
    {
        return $this->config->get('php_version', $this->getDefaultVersion());
    }

    /**
     * Get installed PHP version.
     * @return string
     */
    protected function getDefaultVersion()
    {
        return $this->normalizePhpVersion(PHP_VERSION);
    }

    protected function getExtensionPrefix($version = null)
    {
        $version = $version ?: $this->getCurrentVersion();
        $versionWithoutDot = preg_replace('~[^\d]~', '', $version);
        $prefix = $this->pm->getPhpExtensionPattern($version);
        return str_array_replace([
            'VERSION' => $version,
            'VERSION_WITHOUT_DOT' => $versionWithoutDot,
        ], $prefix);
    }
}
