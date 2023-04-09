<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class DevTools
{
    /**
     * Sublime binary selector.
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
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     * @param Filesystem     $files
     *
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
    }

    /**
     * @param $service
     *
     * @return false|string
     */
    public function ensureInstalled($service)
    {
        return $this->getBin($service);
    }

    /**
     * @param $service
     *
     * @return false|string
     */
    public function getBin($service)
    {
        if (!($bin = $this->getService($service))) {
            $bin = $this->getService($service, true);
        }
        $bins = preg_split('/\n/', $bin);
        $servicePath = null;
        foreach ($bins as $bin) {
            if (ends_with($bin, "bin/${service}")) {
                $servicePath = $bin;
                break;
            }
        }
        if ($servicePath) {
            return trim(preg_replace('/\s\s+/', ' ', $servicePath));
        }

        return false;
    }

    /**
     * @param string $service
     * @param bool   $locate
     *
     * @return false|string
     */
    public function getService(string $service, bool $locate = false)
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

    /**
     * @param $service
     * @param $folder
     *
     * @return void
     */
    public function runService($service, $folder = null)
    {
        $bin = $this->getBin($service);

        try {
            $this->cli->quietly("$bin $folder");
        } catch (DomainException $e) {
            warning("Error while opening [$folder] with $service");
        }
    }

    /**
     * @param string $folder
     * @param string $service
     *
     * @return void
     */
    public function run(string $folder, string $service)
    {
        if ($this->ensureInstalled($service)) {
            $this->runService($service, $folder);
        } else {
            warning("$service not available");
        }
    }
}
