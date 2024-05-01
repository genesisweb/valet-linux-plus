<?php

namespace Valet;

use ConsoleComponents\Writer;
use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class DevTools
{
    /**
     * Sublime binary selector.\
     */
    const VS_CODE = 'code';
    /**
     * Sublime binary selector.
     */
    const SUBLIME = 'subl';

    /**
     * PHPStorm binary selector.
     */
    const PHP_STORM = 'phpstorm.sh';

    /**
     * Atom binary selector.
     */
    const ATOM = 'atom';

    public PackageManager $pm;
    public ServiceManager $sm;
    public CommandLine $cli;
    public Filesystem $files;

    /**
     * Create a new DevTools instance.
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
    }

    /**
     * @param string[] $ignoredServices
     * @return false|string
     */
    public function getBin(string $service, array $ignoredServices = []): false|string
    {
        $selectedService = $this->getService($service);
        if ($service === false) {
            return false;
        }

        $bin = trim($selectedService, "\n");

        if (count($ignoredServices) && in_array($bin, $ignoredServices)) {
            $bin = null;
        }

        if (!$bin) {
            $bin = $this->getServiceByLocate("bin/$service");
        }

        if ($bin === false) {
            return false;
        }

        /** @var string[] $bins */
        $bins = preg_split('/\n/', $bin);
        $servicePath = null;
        foreach ($bins as $bin) {
            if (str_ends_with($bin, "bin/$service")
                && count($ignoredServices)
                && !in_array($bin, $ignoredServices)
            ) {
                $servicePath = $bin;
                break;
            }
        }
        if ($servicePath !== null) {
            /** @var string $servicePath */
            $servicePath = preg_replace('/\s\s+/', ' ', $servicePath);
            return trim($servicePath);
        }

        return false;
    }

    public function run(string $folder, string $service): void
    {
        if ($this->ensureInstalled($service)) {
            $this->runService($service, $folder);
        } else {
            Writer::warn("$service not available");
        }
    }

    /**
     * @return false|string
     */
    private function ensureInstalled(string $service)
    {
        return $this->getBin($service);
    }

    /**
     * @return false|string
     */
    private function getService(string $service)
    {
        try {
            return $this->cli->run(
                "which $service",
                function () {
                    throw new DomainException('Service not available');
                }
            );
        } catch (DomainException $e) {
            return false;
        }
    }

    /**
     * @return false|string
     */
    private function getServiceByLocate(string $service)
    {
        try {
            return $this->cli->run(
                "locate --regex $service$",
                function () {
                    throw new DomainException('Service not available');
                }
            );
        } catch (DomainException $e) {
            return false;
        }
    }

    private function runService(string $service, ?string $folder = null): void
    {
        $bin = $this->getBin($service);

        try {
            $this->cli->quietly("$bin $folder");
        } catch (DomainException $e) {
            Writer::warn("Error while opening [$folder] with $service");
        }
    }
}
