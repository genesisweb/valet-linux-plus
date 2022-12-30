<?php
/**
 * Created by PhpStorm.
 * User: uttam
 * Date: 31/10/19
 * Time: 5:16 PM.
 */

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
     *
     * @return void
     */
    public function install()
    {
        $this->pm->ensureInstalled($this->pm->redisPackageName);
        $this->sm->enable($this->pm->redisPackageName);
    }

    /**
     * Returns true if Redis is installed or not.
     *
     * @return bool
     */
    public function installed()
    {
        return $this->pm->installed($this->pm->redisPackageName);
    }

    /**
     * Restart the service.
     *
     * @return void
     */
    public function restart()
    {
        $this->sm->restart($this->pm->redisPackageName);
    }

    /**
     * Stop the service.
     *
     * @return void
     */
    public function stop()
    {
        $this->sm->stop($this->pm->redisPackageName);
    }

    /**
     * Prepare for uninstall.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->stop();
    }
}
