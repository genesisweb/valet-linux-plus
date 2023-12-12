<?php

namespace Valet;

use Exception;
use Tightenco\Collect\Support\Collection;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Exceptions\VersionException;
use Valet\Traits\PhpFpmHelper;

class PhpFpm
{
    use PhpFpmHelper;
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
     *
     * @return void
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
     *
     * @param string|null $version
     * @param bool        $installExt
     *
     * @throws VersionException
     *
     * @return void
     */
    public function install(string $version = null, bool $installExt = true)
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
     *
     * @return void
     */
    public function uninstall()
    {
        if ($this->files->exists($this->fpmConfigPath().'/'.self::FPM_CONFIG_FILE_NAME)) {
            $this->files->unlink($this->fpmConfigPath().'/'.self::FPM_CONFIG_FILE_NAME);
            $this->stop();
        }
    }

    /**
     * Change the php-fpm version.
     *
     * @param string|float|int $version
     * @param bool|null        $updateCli
     * @param bool|null        $ignoreExt
     * @param bool|null        $ignoreUpdate
     *
     * @throws Exception
     *
     * @return void
     */
    public function switchVersion($version = null, bool $updateCli = false, bool $ignoreExt = false, bool $ignoreUpdate = false)
    {
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
        $this->nginx->restart();
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
        $this->sm->printStatus($this->serviceName($version));
    }

    /**
     * Isolate a given directory to use a specific version of PHP.
     *
     * @param string $directory
     * @param string $version
     * @param bool   $secure
     *
     * @throws VersionException
     *
     * @return void
     */
    public function isolateDirectory($directory, $version, $secure = false)
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

    /**
     * List isolated directories with version.
     *
     * @return Collection
     */
    public function isolatedDirectories()
    {
        return $this->nginx->configuredSites()->filter(function ($item) {
            return strpos($this->files->get(VALET_HOME_PATH.'/Nginx/'.$item), ISOLATED_PHP_VERSION) !== false;
        })->map(function ($item) {
            return ['url' => $item, 'version' => $this->normalizePhpVersion($this->site->customPhpVersion($item))];
        });
    }

    /**
     * Get FPM socket file name for a given PHP version.
     *
     * @param string|float|null $version
     *
     * @return string
     */
    public function socketFileName($version = null)
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
    public function normalizePhpVersion($version)
    {
        return substr(preg_replace('/(?:php@?)?([0-9+])(?:.)?([0-9+])/i', '$1.$2', (string) $version), 0, 3);
    }

    /**
     * Get installed PHP version.
     *
     * @return string
     */
    public function getCurrentVersion()
    {
        return $this->config->get('php_version', $this->getDefaultVersion());
    }

    /**
     * Get executable php path.
     *
     * @param $version
     *
     * @return false|string
     */
    public function getPhpExecutablePath($version = null)
    {
        if (!$version) {
            return \DevTools::getBin('php');
        }

        $version = $this->normalizePhpVersion($version);

        return \DevTools::getBin('php'.$version);
    }
}
