<?php

namespace Valet\ServiceManagers;

use ConsoleComponents\Writer;
use DomainException;
use Valet\CommandLine;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;

class Systemd implements ServiceManager
{
    /**
     * @var CommandLine
     */
    private $cli;
    /**
     * @var Filesystem
     */
    private $files;

    /**
     * Create a new Systemd instance.
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
            $this->cli->quietly('sudo systemctl start '.$this->getRealService($service));
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
            $this->cli->quietly('sudo systemctl stop '.$this->getRealService($service));
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
            $this->cli->quietly('sudo systemctl restart '.$this->getRealService($service));
        }
    }

    /**
     * Status of the given services.
     */
    public function printStatus(string $service): void
    {
        $status = $this->cli->run('systemctl status '.$this->getRealService($service).' | grep "Active:"');
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

        return !str_contains(trim($this->cli->run(\sprintf('systemctl is-enabled %s', $service))), 'enabled');
    }

    /**
     * Enable services.
     */
    public function enable(string $service): void
    {
        try {
            $service = $this->getRealService($service);

            if ($this->disabled($service)) {
                $this->cli->quietly('sudo systemctl enable '.$service);
            }

            Writer::twoColumnDetail(ucfirst($service), 'Enabled');
        } catch (DomainException $e) {
            Writer::warn(ucfirst($service).' unavailable.');
        }
    }

    /**
     * Disable services.
     */
    public function disable(string $service): void
    {
        try {
            $service = $this->getRealService($service);

            if (!$this->disabled($service)) {
                $this->cli->quietly('sudo systemctl disable '.$service);
            }

            Writer::twoColumnDetail(ucfirst($service), 'Disabled');
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
                'which systemctl',
                function () {
                    throw new DomainException('Systemd not available');
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
        $servicePath = '/etc/systemd/system/valet-dns.service';
        if ($this->files->exists($servicePath)) {
            Writer::info('Removing Valet DNS service...');
            $this->disable('valet-dns');
            $this->stop('valet-dns');
            $this->files->remove($servicePath);
        }
    }

    public function isSystemd(): bool
    {
        return true;
    }

    /**
     * Determine real service name.
     * @throws DomainException
     */
    private function getRealService(string $service): string
    {
        return collect($service)->first(
            function ($service) {
                return strpos($this->cli->run("systemctl status $service | grep Loaded"), 'Loaded: loaded');
            },
            function () {
                throw new DomainException('Unable to determine service name.');
            }
        );
    }
}
