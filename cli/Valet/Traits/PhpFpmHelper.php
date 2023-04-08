<?php

namespace Valet\Traits;

use Exception;
use Valet\Exceptions\VersionException;

trait PhpFpmHelper
{
    /**
     * Stop a given PHP version, if that specific version isn't being used globally or by any sites.
     * @param string|float|null $version
     * @return void
     */
    private function stopIfUnused($version = null)
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
     * Determine php service name.
     *
     * @return string
     */
    private function serviceName($version = null)
    {
        if (!$version) {
            $version = $this->getCurrentVersion();
        }
        return $this->getExtensionPrefix($version) . '-fpm';
    }

    private function updateNginxConfigFiles($version) {
        //Action 1: Update all separate secured versions
        $this->nginx->configuredSites()->map(function($file) use ($version) {
            $content = $this->files->get(VALET_HOME_PATH . '/Nginx/' . $file);
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
                'unix:'.VALET_HOME_PATH. '/'.$this->socketFileName($version),
                $content
            );
            $this->files->put(VALET_HOME_PATH.'/Nginx/'.$file, $content);
        });

        //Action 2: Update NGINX valet.conf for php socket version.
        $this->nginx->installServer($version);
    }

    private function installExtensions($version)
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
    private function installConfiguration($version)
    {
        $contents = $this->files->get(__DIR__ . '/../../stubs/fpm.conf');

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
    private function utilizedPhpVersions()
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
     * Get the path to the FPM configuration file for the current PHP version.
     *
     * @return string
     */
    private function fpmConfigPath($version = null)
    {
        $version = $version ?: $this->getCurrentVersion();
        $versionWithoutDot = preg_replace('~[^\d]~', '', $version);

        return collect([
            '/etc/php/' . $version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $version . '/fpm/pool.d', // Ubuntu
            '/etc/php' . $version . '/php-fpm.d', // Manjaro
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
     * Validate PHP version
     * @return void
     * @throws VersionException
     */
    private function validateVersion($version)
    {
        if (!in_array($version, self::SUPPORTED_PHP_VERSIONS)) {
            throw new VersionException(
                "Invalid version [$version] used. Supported versions are :" . implode(self::SUPPORTED_PHP_VERSIONS)
            );
        }
    }

    /**
     * Get installed PHP version.
     * @return string
     */
    private function getDefaultVersion()
    {
        return $this->normalizePhpVersion(PHP_VERSION);
    }

    private function getExtensionPrefix($version = null)
    {
        $version = $version ?: $this->getCurrentVersion();
        $versionWithoutDot = preg_replace('~[^\d]~', '', $version);
        $prefix = $this->pm->getPhpExtensionPattern($version);
        return str_array_replace([
            '{VERSION}' => $version,
            '{VERSION_WITHOUT_DOT}' => $versionWithoutDot,
        ], $prefix);
    }

    private function handlePackageUpdate($version) {
        $installedPhpVersion = $this->config->get('installed_php_version');
        if ($installedPhpVersion && $installedPhpVersion >= $version) {
            if (is_dir(__DIR__.'/../../../vendor')) {
                // Local vendor
                $this->cli->runAsUser('composer update');
            } else {
                // Global vendor
                $this->cli->runAsUser('composer global require genesisweb/valet-linux-plus:'.VALET_VERSION. ' -W');
            }
            $this->config->set('installed_php_version', $version);
        }
    }

}
