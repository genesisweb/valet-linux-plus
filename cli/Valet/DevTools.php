<?php

namespace Valet;

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

    /**
     * @var PackageManager
     */
    public $pm;
    /**
     * @var ServiceManager
     */
    public $sm;
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var Filesystem
     */
    public $files;

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
     * @return false|string
     */
    public function getBin(string $service)
    {
        if (!($bin = $this->getService($service))) {
            $bin = $this->getService($service, true);
        }
        $bins = preg_split('/\n/', $bin);
        $servicePath = null;
        foreach ($bins as $bin) {
            if (endsWith($bin, "bin/${service}")) {
                $servicePath = $bin;
                break;
            }
        }
        if ($servicePath) {
            return trim(preg_replace('/\s\s+/', ' ', $servicePath));
        }

        return false;
    }

    public function run(string $folder, string $service): void
    {
        if ($this->ensureInstalled($service)) {
            $this->runService($service, $folder);
        } else {
            warning("$service not available");
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
    private function getService(string $service, bool $locate = false)
    {
        try {
            $locator = $locate ? 'locate' : 'which';

            return $this->cli->run(
                "$locator $service",
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
            warning("Error while opening [$folder] with $service");
        }
    }
}
