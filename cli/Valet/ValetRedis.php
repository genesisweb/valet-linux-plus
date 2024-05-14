<?php

namespace Valet;

use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

/**
 * Class ValetRedis.
 */
class ValetRedis
{
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
     * Create a new PHP FPM class instance.
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     *
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
    }

    /**
     * Install Redis Server.
     */
    public function install(): void
    {
        $packageName = $this->pm->packageName('redis');
        $this->pm->ensureInstalled($packageName);
        $this->sm->enable($packageName);
    }

    /**
     * Returns true if Redis is installed or not.
     */
    public function installed(): bool
    {
        return $this->pm->installed($this->pm->packageName('redis'));
    }

    /**
     * Restart the service.
     */
    public function restart(): void
    {
        $this->sm->restart($this->pm->packageName('redis'));
    }

    /**
     * Stop the service.
     */
    public function stop(): void
    {
        $this->sm->stop($this->pm->packageName('redis'));
    }

    /**
     * Prepare for uninstall.
     */
    public function uninstall(): void
    {
        $this->stop();
    }
}
