<?php

namespace Valet;

use DomainException;
use Exception;
use Tightenco\Collect\Support\Collection;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\PackageManagers\Pacman;

class PhpFpm
{
    public $pm;
    public $sm;
    public $cli;
    public $files;
    public $site;
    public $nginx;
    public $version;
    public $latestVersion = '8.2';

    protected $commonExt = [
        'common',
        'cli',
        'mysql',
        'gd',
        'zip',
        'xml',
        'curl',
        'mbstring',
        'pgsql',
        'mongodb',
        'intl',
    ];

    /**
     * Create a new PHP FPM class instance.
     *
     * @param  PackageManager  $pm
     * @param  ServiceManager  $sm
     * @param  CommandLine  $cli
     * @param  Filesystem  $files
     * @param  Site  $site
     * @param  Nginx  $nginx
     *
     * @return void
     */
    public function __construct(
        PackageManager $pm,
        ServiceManager $sm,
        CommandLine $cli,
        Filesystem $files,
        Site $site,
        Nginx $nginx
    ) {
        $this->cli     = $cli;
        $this->pm      = $pm;
        $this->sm      = $sm;
        $this->files   = $files;
        $this->version = $this->getVersion();
        $this->site    = $site;
        $this->nginx   = $nginx;
    }

    /**
     * Install and configure PHP FPM.
     *
     * @return void
     */
    public function install($version = null)
    {
        $version = $version ?: $this->version;

        if ( ! $this->pm->installed("php{$version}-fpm")) {
            $this->pm->ensureInstalled("php{$version}-fpm");
            $this->installExtensions($version);
            $this->sm->enable($this->fpmServiceName());
        }

        $this->files->ensureDirExists('/var/log', user());

        $this->installConfiguration();

        $this->restart();
    }

    /**
     * Uninstall PHP FPM valet config.
     *
     * @return void
     */
    public function uninstall()
    {
        if ($this->files->exists($this->fpmConfigPath() . '/valet.conf')) {
            $this->files->unlink($this->fpmConfigPath() . '/valet.conf');
            $this->stop();
        }
    }

    public function installExtensions($version = null)
    {
        $version = $version ?: $this->version;
        if ($this->pm instanceof Pacman) {
            warning('PHP Extension install is not supported for Pacman package manager');

            return;
        }
        $extArray = [];
        foreach ($this->commonExt as $ext) {
            $extArray[] = "php{$version}-{$ext}";
        }
        $this->pm->ensureInstalled(implode(' ', $extArray));
    }

    /**
     * Change the php-fpm version.
     *
     * @param  string|float|int  $version
     * @param  bool|null  $updateCli
     * @param  bool|null  $installExt
     *
     * @return void
     * @throws Exception
     *
     */
    public function changeVersion($version = null, bool $updateCli = null, bool $installExt = null)
    {
        $oldVersion = $this->version;
        $exception  = null;

        $this->stop();
        info('Disabling php' . $this->version . '-fpm...');
        $this->sm->disable($this->fpmServiceName());

        if ( ! isset($version) || strtolower($version) === 'default') {
            $version = $this->getVersion(true);
        }


        $this->version = $version;

        try {
            $this->install();
        } catch (DomainException $e) {
            $this->version = $oldVersion;
            $exception     = $e;
        }

        $newVersion = $this->version == $this->latestVersion ? '' : $this->version;


        if ($this->sm->disabled($this->fpmServiceName())) {
            info('Enabling php' . $newVersion . '-fpm...');
            $this->sm->enable($this->fpmServiceName());
        }

        if ($newVersion !== $oldVersion) {
            $this->files->putAsUser(VALET_HOME_PATH . '/use_php_version', $newVersion);
        } else {
            $this->files->unlink(VALET_HOME_PATH . '/use_php_version');
        }
        if ($updateCli) {
            $this->cli->run("update-alternatives --set php /usr/bin/php{$newVersion}");
        }
        if ($installExt) {
            $this->installExtensions();
        }

        if ($exception) {
            info('Changing version failed');

            throw $exception;
        }
    }

    /**
     * Update the PHP FPM configuration to use the current user.
     *
     * @return void
     */
    public function installConfiguration()
    {
        $contents = $this->files->get(__DIR__ . '/../stubs/fpm.conf');

        $this->files->putAsUser(
            $this->fpmConfigPath() . '/valet.conf',
            str_array_replace([
                'VALET_USER'      => user(),
                'VALET_GROUP'     => group(),
                'VALET_HOME_PATH' => VALET_HOME_PATH,
            ], $contents)
        );
    }

    /**
     * Restart the PHP FPM process.
     *
     * @return void
     */
    public function restart($version = null)
    {
        $this->sm->restart($this->fpmServiceName($version));
    }

    /**
     * Stop the PHP FPM process.
     *
     * @return void
     */
    public function stop()
    {
        $this->sm->stop($this->fpmServiceName());
    }

    /**
     * Stop a given PHP version, if that specific version isn't being used globally or by any sites.
     *
     * @param  string|null  $phpVersion
     *
     * @return void
     */
    public function stopIfUnused($phpVersion = null)
    {
        if ( ! $phpVersion) {
            return;
        }

        $phpVersion = $this->normalizePhpVersion($phpVersion);

        if ( ! in_array($phpVersion, $this->utilizedPhpVersions())) {
            $this->sm->stop($this->fpmServiceName($phpVersion));
        }
    }

    /**
     * PHP-FPM service status.
     *
     * @return void
     */
    public function status()
    {
        $this->sm->printStatus($this->fpmServiceName());
    }

    /**
     * Get installed PHP version.
     *
     * @param  bool  $real  force getting version from /usr/bin/php.
     *
     * @return string
     */
    public function getVersion($real = false)
    {
        if ( ! $real && $this->files->exists(VALET_HOME_PATH . '/use_php_version')) {
            $version = $this->files->get(VALET_HOME_PATH . '/use_php_version');
        } else {
            $version = explode('php', basename($this->files->readLink('/usr/bin/php')))[1];
        }

        if ( ! $real && $this->pm instanceof Pacman && $version == $this->latestVersion) {
            return '';
        }

        return $version;
    }

    /**
     * Determine php service name.
     *
     * @return string
     */
    public function fpmServiceName($version = null)
    {
        if ( ! $version) {
            $version = $this->getPhpVersion();
        }
        if ($this->pm instanceof Pacman) {
            $version = str_replace('.', '', $version);
        }
        $serviceNamePattern = $this->pm->getPhpServicePattern();

        return str_replace('{VERSION}', $version, $serviceNamePattern);
    }

    /**
     * Isolate a given directory to use a specific version of PHP.
     *
     * @param  string  $directory
     * @param  string  $version
     * @param  bool  $secure
     *
     * @return void
     */
    public function isolateDirectory($directory, $version, $secure = false)
    {
        $site = $this->site->getSiteUrl($directory);

        $version = $this->validateRequestedVersion($version);

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "74"
        $this->createConfigurationFiles($version);

        $this->site->isolate($site, $version, $secure);

        $this->stopIfUnused($oldCustomPhpVersion);
        $this->restart($version);
        $this->nginx->restart();

        info(sprintf('The site [%s] is now using %s.', $site, $version));
    }

    /**
     * Remove PHP version isolation for a given directory.
     *
     * @param  string  $directory
     *
     * @return void
     */
    public function unIsolateDirectory($directory)
    {
        $site = $this->site->getSiteUrl($directory);

        $oldCustomPhpVersion = $this->site->customPhpVersion($site); // Example output: "74"

        $this->site->removeIsolation($site);
        $this->stopIfUnused($oldCustomPhpVersion);
        $this->nginx->restart();

        info(sprintf('The site [%s] is now using the default PHP version.', $site));
    }

    /**
     * List all directories with PHP isolation configured.
     *
     * @return Collection
     */
    public function isolatedDirectories()
    {
        return $this->nginx->configuredSites()->filter(function ($item) {
            return strpos($this->files->get(VALET_HOME_PATH . '/Nginx/' . $item), ISOLATED_PHP_VERSION) !== false;
        })->map(function ($item) {
            return ['url' => $item, 'version' => $this->normalizePhpVersion($this->site->customPhpVersion($item))];
        });
    }

    /**
     * Symlink (Capistrano-style) a given Valet.sock file to be the primary valet.sock.
     *
     * @param  string  $phpVersion
     *
     * @return void
     */
    public function symlinkPrimaryValetSock($phpVersion = null)
    {
        if ( ! $phpVersion) {
            $phpVersion = $this->getPhpVersion();
        }

        $this->files->symlinkAsUser(
            VALET_HOME_PATH . '/' . $this->fpmSockName($phpVersion),
            VALET_HOME_PATH . '/valet.sock'
        );
    }

    /**
     * If passed php7.4, or php74, 7.4, or 74 formats, normalize to php@7.4 format.
     */
    public static function normalizePhpVersion($version)
    {
        return substr(preg_replace('/(?:php@?)?([0-9+])(?:.)?([0-9+])/i', '$1.$2', (string)$version), 0, 3);
    }

    /**
     * Validate the requested version to be sure we can support it.
     *
     * @param  string  $version
     *
     * @return string
     */
    public function validateRequestedVersion($version): string
    {
        if (is_null($version)) {
            throw new DomainException("Please specify a PHP version (try something like 'php8.1')");
        }

        $version = $this->normalizePhpVersion($version);

        if ( ! $this->pm->installed("php{$version}-fpm")) {
            $this->install($version);
        }

        return $version;
    }

    /**
     * Get FPM sock file name for a given PHP version.
     *
     * @param  string|null  $phpVersion
     *
     * @return string
     */
    public static function fpmSockName($phpVersion = null)
    {
        if ( ! $phpVersion) {
            $phpVersion = \PhpFpm::getPhpVersion();
        }

        $versionInteger = preg_replace('~[^\d]~', '', $phpVersion);

        return "valet{$versionInteger}.sock";
    }

    /**
     * Create (or re-create) the PHP FPM configuration files.
     *
     * Writes FPM config file, pointing to the correct .sock file, and log and ini files.
     *
     * @param  string  $phpVersion
     *
     * @return void
     */
    public function createConfigurationFiles($phpVersion)
    {
        info("Updating PHP configuration for {$phpVersion}...");

        $fpmConfigFile = $this->fpmConfigPath($phpVersion) . '/valet.conf';

        $this->files->ensureDirExists(dirname($fpmConfigFile), user());

        // rename (to disable) old FPM Pool configuration, regardless of whether it's a default config or one customized by an older Valet version
        $oldFile = dirname($fpmConfigFile) . '/www.conf';
        if (file_exists($oldFile)) {
            $this->cli->run("mv $oldFile $oldFile-backup");
        }

        // Create FPM Config File from stub
        $contents = str_replace(
            ['VALET_USER', 'VALET_HOME_PATH', 'valet.sock'],
            [user(), VALET_HOME_PATH, self::fpmSockName($phpVersion)],
            $this->files->get(__DIR__ . '/../stubs/etc-phpfpm-valet.conf')
        );
        $this->files->put($fpmConfigFile, $contents);

        // Create other config files from stubs
        $destDir = dirname(dirname($fpmConfigFile)) . '/conf.d';
        $this->files->ensureDirExists($destDir, user());

        // Create log directory and file
        $this->files->ensureDirExists(VALET_HOME_PATH . '/Log', user());
        $this->files->touch(VALET_HOME_PATH . '/Log/php-fpm.log', user());
    }

    /**
     * Get a list including the global PHP version and all PHP versions currently serving "isolated sites" (sites with
     * custom Nginx configs pointing them to a specific PHP version).
     *
     * @return array
     */
    public function utilizedPhpVersions()
    {
        $fpmSockFiles = $this->pm->supportedPhpVersions()->map(function ($version) {
            return self::fpmSockName($this->normalizePhpVersion($version));
        })->unique();

        return $this->nginx->configuredSites()->map(function ($file) use ($fpmSockFiles) {
            $content = $this->files->get(VALET_HOME_PATH . '/Nginx/' . $file);

            // Get the normalized PHP version for this config file, if it's defined
            foreach ($fpmSockFiles as $sock) {
                if (strpos($content, $sock) !== false) {
                    // Extract the PHP version number from a custom .sock path and normalize it to, e.g., "php@7.4"
                    return $this->normalizePhpVersion(str_replace(['valet', '.sock'], '', $sock));
                }
            }
        })->filter()->unique()->values()->toArray();
    }

    /**
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    public function fpmConfigPath($phpVersion = null)
    {
        $phpVersion = $phpVersion ?: $this->getPhpVersion();

        return collect([
            '/etc/php/' . $phpVersion . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $phpVersion . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $phpVersion . '/php-fpm.d', // Manjaro
            '/etc/php-fpm.d', // Fedora
            '/etc/php/php-fpm.d', // Arch
            '/etc/php7/fpm/php-fpm.d', // openSUSE PHP7
            '/etc/php8/fpm/php-fpm.d', // openSUSE PHP8
        ])->first(function ($path) {
            return is_dir($path);
        }, function () {
            throw new DomainException('Unable to determine PHP-FPM configuration folder.');
        });
    }

    public function getPhpVersion()
    {
        if ( ! $this->version) {
            $this->version = $this->normalizePhpVersion(PHP_VERSION);
        }

        if ($this->pm instanceof Pacman && $this->version == $this->latestVersion) {
            return '';
        }

        return $this->version;
    }

    public function getPhpExecutablePath($phpVersion = null)
    {
        if ( ! $phpVersion) {
            return \DevTools::getBin('php');
        }

        $phpVersion = $this->normalizePhpVersion($phpVersion);

        return \DevTools::getBin('php' . $phpVersion);
    }
}
