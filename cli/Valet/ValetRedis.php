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
        $this->pm->ensureInstalled($this->pm->redisPackageName);
        $this->sm->enable($this->pm->redisPackageName);
    }

    /**
     * Returns true if Redis is installed or not.
     */
    public function installed(): bool
    {
        return $this->pm->installed($this->pm->redisPackageName);
    }

    /**
     * Restart the service.
     */
    public function restart(): void
    {
        $this->sm->restart($this->pm->redisPackageName);
    }

    /**
     * Stop the service.
     */
    public function stop(): void
    {
        $this->sm->stop($this->pm->redisPackageName);
    }

    /**
     * Prepare for uninstall.
     */
    public function uninstall(): void
    {
        $this->stop();
    }
}
