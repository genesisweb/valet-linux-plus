<?php

namespace Valet\ServiceManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;

class LinuxService implements ServiceManager
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
     * Create a new Linux instance.
     */
    public function __construct(CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Start the given services.
     * @param string|string[]|null $services Service name
     */
    public function start(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Starting');
            $this->cli->quietly('sudo service '.$this->getRealService($service).' start');
        }
    }

    /**
     * Stop the given services.
     * @param string|string[]|null $services Service name
     */
    public function stop(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Stopping');
            $this->cli->quietly('sudo service '.$this->getRealService($service).' stop');
        }
    }

    /**
     * Restart the given services.
     * @param string|string[]|null $services Service name
     */
    public function restart(array|string|null $services): void
    {
        /** @var string[] $services */
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            Writer::twoColumnDetail(ucfirst($service), 'Restarting');
            $this->cli->quietly('sudo service '.$this->getRealService($service).' restart');
        }
    }

    /**
     * Status of the given services.
     */
    public function printStatus(string $service): void
    {
        $status = $this->cli->run('service '.$this->getRealService($service). ' status');
        $running = strpos(trim($status), 'running');

        if ($running) {
            Writer::info(ucfirst($service).' is running...');
        } else {
            Writer::warn(ucfirst($service).' is stopped...');
        }
    }

    /**
     * Check if service is disabled.
     */
    public function disabled(string $service): bool
    {
        $service = $this->getRealService($service);
// TODO: Do not use systemctl and stop using linux service class if systemd is available on all minimum versions
        return !str_contains(trim($this->cli->run("systemctl is-enabled {$service}")), 'enabled');
    }

    /**
     * Disable services.
     */
    public function disable(string $service): void
    {
        try {
            $service = $this->getRealService($service);
            $this->cli->quietly("sudo chmod -x /etc/init.d/{$service}");
            $this->cli->quietly("sudo update-rc.d $service defaults");

            Writer::twoColumnDetail(ucfirst($service), 'Disabled');
        } catch (DomainException $e) {
            Writer::warn(ucfirst($service).' not available.');
        }
    }

    /**
     * Enable services.
     */
    public function enable(string $service): void
    {
        try {
            $service = $this->getRealService($service);
            $this->cli->quietly("sudo update-rc.d $service defaults");
            Writer::twoColumnDetail(ucfirst($service), 'Enabled');
        } catch (DomainException $e) {
            Writer::warn(ucfirst($service).' unavailable.');
        }
    }

    /**
     * Determine if service manager is available on the system.
     */
    public function isAvailable(): bool
    {
        try {
            $output = $this->cli->run(
                'which service',
                function () {
                    throw new DomainException('Service not available');
                }
            );

            return $output != '';
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * Remove Valet DNS services.
     */
    public function removeValetDns(): void
    {
        $servicePath = '/etc/init.d/valet-dns';

        if ($this->files->exists($servicePath)) {
            Writer::info('Removing Valet DNS service...');
            $this->disable('valet-dns');
            $this->stop('valet-dns');
            $this->files->remove($servicePath);
        }
    }

    public function isSystemd(): bool
    {
        return false;
    }

    /**
     * Determine real service name.
     */
    private function getRealService(string $service): string
    {
        return collect($service)->first(
            function ($service) {
                return !strpos(
                    $this->cli->run('service '.$service.' status'),
                    'not-found'
                );
            },
            function () {
                throw new DomainException('Unable to determine service name.');
            }
        );
    }
}
