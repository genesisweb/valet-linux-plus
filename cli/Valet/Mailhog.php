<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class Mailhog
{
    public $pm;
    public $sm;
    public $cli;
    public $files;

    /**
     * Create a new MailHog instance.
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
     * Install the configuration files for MailHog.
     *
     * @return void
     */
    public function install()
    {
        $this->ensureInstalled();

        $this->createService();

        $this->sm->start('mailhog');
    }

    /**
     * Validate if system already has MailHog installed in it.
     *
     * @return void
     */
    public function ensureInstalled()
    {
        if (!$this->isAvailable()) {
            $this->cli->run('ln -s '.VALET_BIN_PATH.'/mailhog /opt/valet-linux/mailhog');
        }
    }

    /**
     * @return void
     */
    public function createService()
    {
        info('Installing MailHog service...');

        $servicePath = '/etc/init.d/mailhog';
        $serviceFile = VALET_ROOT_PATH.'/cli/stubs/init/mailhog.sh';
        $hasSystemd = $this->sm->_hasSystemd();

        if ($hasSystemd) {
            $servicePath = '/etc/systemd/system/mailhog.service';
            $serviceFile = VALET_ROOT_PATH.'/cli/stubs/init/mailhog';
        }

        $this->files->put(
            $servicePath,
            $this->files->get($serviceFile)
        );

        if (!$hasSystemd) {
            $this->cli->run("chmod +x $servicePath");
        }

        $this->sm->enable('mailhog');

        $this->updateDomain();

        \Nginx::restart();
    }

    /**
     * Update domain for HTTP access.
     *
     * @return void
     */
    public function updateDomain()
    {
        $domain = \Configuration::read()['domain'];

        \Site::secure("mailhog.{$domain}", __DIR__.'/../stubs/mailhog.conf');
    }

    /**
     * @return bool
     */
    public function isAvailable()
    {
        try {
            $output = $this->cli->run(
                'which mailhog',
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
     * Start the MailHog service.
     *
     * @return void
     */
    public function start()
    {
        $this->sm->start('mailhog');
    }

    /**
     * Restart the MailHog service.
     *
     * @return void
     */
    public function restart()
    {
        $this->sm->restart('mailhog');
    }

    /**
     * Stop the MailHog service.
     *
     * @return void
     */
    public function stop()
    {
        $this->sm->stop('mailhog');
    }

    /**
     * MailHog service status.
     *
     * @return void
     */
    public function status()
    {
        $this->sm->printStatus('mailhog');
    }

    /**
     * Prepare MailHog for uninstall.
     *
     * @return void
     */
    public function uninstall()
    {
        $this->stop();
    }
}
