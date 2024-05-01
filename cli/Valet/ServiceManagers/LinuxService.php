<?php

namespace Valet\ServiceManagers;

use DomainException;
use Valet\CommandLine;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use function Valet\info;
use function Valet\warning;

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
     * @param array|string $services Service name
     */
    public function start($services): void
    {
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            info("Starting $service...");
            $this->cli->quietly('sudo service '.$this->getRealService($service).' start');
        }
    }

    /**
     * Stop the given services.
     * @param array|string $services Service name
     */
    public function stop($services): void
    {
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            info("Stopping $service...");
            $this->cli->quietly('sudo service '.$this->getRealService($service).' stop');
        }
    }

    /**
     * Restart the given services.
     * @param array|string $services Service name
     */
    public function restart($services): void
    {
        $services = is_array($services) ? $services : func_get_args();

        foreach ($services as $service) {
            info("Restarting $service...");
            $this->cli->quietly('sudo service '.$this->getRealService($service).' restart');
        }
    }

    /**
     * Status of the given services.
     */
    public function printStatus(string $service): void
    {
        info($this->cli->run('service '.$this->getRealService($service)));
    }

    /**
     * Check if service is disabled.
     */
    public function disabled(string $service): bool
    {
        $service = $this->getRealService($service);

        return strpos(trim($this->cli->run("systemctl is-enabled {$service}")), 'enabled') === false;
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
        } catch (DomainException $e) {
            warning(ucfirst($service).' not available.');
        }
    }

    /**
     * Enable services.
     *
     * @param mixed $services Service or services to enable
     *
     * @return void
     */
    public function enable(string $service): void
    {
        try {
            $service = $this->getRealService($service);
            $this->cli->quietly("sudo update-rc.d $service defaults");
            info(ucfirst($service).' has been enabled');
        } catch (DomainException $e) {
            warning(ucfirst($service).' not available.');
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
            info('Removing Valet DNS service...');
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
