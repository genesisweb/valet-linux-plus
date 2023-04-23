<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class Mailpit
{
    public $pm;
    public $sm;
    public $cli;
    public $files;

    const SERVICE_NAME = 'mailpit';

    /**
     * Create a new MailHog instance.
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine $cli
     * @param Filesystem $files
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

        $this->sm->start(self::SERVICE_NAME);

        if (!$this->sm->disabled('mailhog')) {
            $this->sm->disable('mailhog');
            if ($this->files->exists('/opt/valet-linux/mailhog')) {
                $this->files->remove('/opt/valet-linux/mailhog');
            }
            $domain = \Configuration::get('domain');
            if ($this->files->exists(VALET_HOME_PATH . "/Nginx/mailhog.$domain")) {
                \Site::proxyDelete("mailhog.$domain");
            }
        }
    }

    /**
     * Validate if system already has MailHog installed in it.
     *
     * @return void
     */
    public function ensureInstalled()
    {
        if (!$this->isAvailable()) {
            $this->cli->run('sudo bash < <(curl -sL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh)');
        }
    }

    /**
     * @return void
     */
    public function createService()
    {
        info('Installing Mailpit service...');

        $servicePath = '/etc/init.d/mailpit';
        $serviceFile = VALET_ROOT_PATH . '/cli/stubs/init/mailpit.sh';
        $hasSystemd = $this->sm->_hasSystemd();

        if ($hasSystemd) {
            $servicePath = '/etc/systemd/system/mailpit.service';
            $serviceFile = VALET_ROOT_PATH . '/cli/stubs/init/mailpit';
        }

        $this->files->put(
            $servicePath,
            $this->files->get($serviceFile)
        );

        if (!$hasSystemd) {
            $this->cli->run("chmod +x $servicePath");
        }

        $this->sm->enable(self::SERVICE_NAME);

        $this->updateDomain();
    }

    /**
     * Update domain for HTTP access.
     *
     * @return void
     */
    public function updateDomain()
    {
        $domain = \Configuration::get('domain');

        \Site::proxyCreate("mails.$domain", 'http://localhost:8025', true);
    }

    /**
     * @return bool
     */
    public function isAvailable()
    {
        try {
            $output = $this->cli->run(
                'which mailpit',
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
        $this->sm->start(self::SERVICE_NAME);
    }

    /**
     * Restart the MailHog service.
     *
     * @return void
     */
    public function restart()
    {
        $this->sm->restart(self::SERVICE_NAME);
    }

    /**
     * Stop the MailHog service.
     *
     * @return void
     */
    public function stop()
    {
        $this->sm->stop(self::SERVICE_NAME);
    }

    /**
     * MailHog service status.
     *
     * @return void
     */
    public function status()
    {
        $this->sm->printStatus(self::SERVICE_NAME);
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
