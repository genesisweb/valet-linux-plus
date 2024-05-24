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
    public const VS_CODE = 'code';

    /**
     * Sublime binary selector.
     */
    public const SUBLIME = 'subl';

    /**
     * PHPStorm binary selector.
     */
    public const PHP_STORM = 'phpstorm.sh';

    /**
     * Atom binary selector.
     */
    public const ATOM = 'atom';

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
        $bin = $this->getService($service);

        $bin = trim($bin, "\n");
        if (count($ignoredServices) && in_array($bin, $ignoredServices)) {
            $bin = null;
        }

        if (!$bin) {
            $bin = $this->getServiceByLocate("bin/$service");
        }

        if (!$bin) {
            return false;
        }

        $bin = trim($bin, "\n");
        /** @var string[] $bins */
        $bins = preg_split('/\n/', $bin);
        $servicePath = null;
        foreach ($bins as $bin) {
            if ((count($ignoredServices) && !in_array($bin, $ignoredServices))
                || !count($ignoredServices)
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
        if ($bin = $this->ensureInstalled($service)) {
            $this->runService($bin, $folder);
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

    private function runService(string $bin, ?string $folder = null): void
    {
        $this->cli->quietly("$bin $folder");
    }
}
