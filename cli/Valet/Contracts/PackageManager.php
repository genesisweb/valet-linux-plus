<?php

namespace Valet\Contracts;

interface PackageManager
{
    /**
     * Determine if the given package is installed.
     */
    public function installed(string $package): bool;

    /**
     * Ensure that the given package is installed.
     */
    public function ensureInstalled(string $package): void;

    /**
     * Install the given package and throw an exception on failure.
     */
    public function installOrFail(string $package): void;

    /**
     * Configure package manager on valet install.
     */
    public function setup(): void;

    /**
     * Determine if package manager is available on the system.
     */
    public function isAvailable(): bool;

    /**
     * Get Php fpm service name from distro
     */
    public function getPhpFpmName(string $version): string;

    /**
     * Get Php extension pattern from distro
     *  TODO: This function is refactored, please update the usage.
     */
    public function getPhpExtensionPrefix(string $version): string;

    /**
     * Restart network manager in distro
     */
    public function restartNetworkManager(): void;
}
