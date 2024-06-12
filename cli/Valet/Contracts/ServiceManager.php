<?php

namespace Valet\Contracts;

interface ServiceManager
{
    /**
     * Start the given services.
     *
     * @param array|string|string[]|null $services
     */
    public function start(array|string|null $services): void;

    /**
     * Stop the given services.
     *
     * @param array|string|string[]|null $services
     */
    public function stop(array|string|null $services): void;

    /**
     * Restart the given services.
     *
     * @param array|string|string[]|null $services
     */
    public function restart(array|string|null $services): void;

    /**
     * Enable the given services.
     */
    public function enable(string $service): void;

    /**
     * Disable the given services.
     */
    public function disable(string $service): void;

    /**
     * Check if service is disabled.
     */
    public function disabled(string $service): bool;

    /**
     * Determine if service manager is available on the system.
     */
    public function isAvailable(): bool;

    /**
     * Status of the given services.
     */
    public function printStatus(string $service): void;

    /**
     * If the service manager is systemd.
     */
    public function isSystemd(): bool;

    /**
     * Remove Valet DNS services.
     */
    public function removeValetDns(): void;
}
