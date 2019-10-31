<?php
/**
 * Created by PhpStorm.
 * User: uttam
 * Date: 31/10/19
 * Time: 5:16 PM
 */

namespace Valet;

use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class Redis
{
    public $pm;
    public $sm;
    public $cli;
    /**
     * Create a new PHP FPM class instance.
     *
     * @param  PackageManager $pm
     * @param  ServiceManager $sm
     * @param  CommandLine  $cli
     * @return void
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, CommandLine $cli)
    {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
    }

    public function install()
    {
        $this->pm->ensureInstalled('redis-server');
        $this->sm->enable('redis-server');
    }
}