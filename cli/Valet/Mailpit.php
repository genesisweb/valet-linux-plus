<?php

namespace Valet;

use DomainException;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\CommandLine;
use Valet\Facades\Configuration;
use Valet\Facades\Site;

class Mailpit
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
     * @var Filesystem
     */
    public $files;
    /**
     * @var string
     */
    const SERVICE_NAME = 'mailpit';

    /**
     * Create a new Mailpit instance.
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
     * Install the configuration files for Mailpit.
     */
    public function install(): void
    {
        $this->ensureInstalled();

        $this->createService();

        $this->sm->start(self::SERVICE_NAME);

        if (!$this->sm->disabled('Mailpit')) {
            $this->sm->disable('Mailpit');
            if ($this->files->exists('/opt/valet-linux/Mailpit')) {
                $this->files->remove('/opt/valet-linux/Mailpit');
            }
            $domain = Configuration::get('domain');
            if ($this->files->exists(VALET_HOME_PATH."/Nginx/Mailpit.$domain")) {
                Site::proxyDelete("Mailpit.$domain");
            }
        }
    }

    /**
     * Start the Mailpit service.
     *
     * @return void
     */
    public function start(): void
    {
        $this->sm->start(self::SERVICE_NAME);
    }

    /**
     * Restart the Mailpit service.
     *
     * @return void
     */
    public function restart(): void
    {
        $this->sm->restart(self::SERVICE_NAME);
    }

    /**
     * Stop the Mailpit service.
     *
     * @return void
     */
    public function stop(): void
    {
        $this->sm->stop(self::SERVICE_NAME);
    }

    /**
     * Mailpit service status.
     *
     * @return void
     */
    public function status(): void
    {
        $this->sm->printStatus(self::SERVICE_NAME);
    }

    /**
     * Prepare Mailpit for uninstall.
     *
     * @return void
     */
    public function uninstall(): void
    {
        $this->stop();
    }

    /**
     * Validate if system already has Mailpit installed in it.
     */
    private function ensureInstalled(): void
    {
        if (!$this->isAvailable()) {
            $this->cli->runAsUser(
                'curl -sL https://raw.githubusercontent.com/axllent/mailpit/develop/install.sh | bash'
            );
        }
    }

    /**
     * Create Mailpit service files
     */
    private function createService(): void
    {
        info('Installing Mailpit service...');

        $servicePath = '/etc/init.d/mailpit';
        $serviceFile = VALET_ROOT_PATH.'/cli/stubs/init/mailpit.sh';
        $hasSystemd = $this->sm->isSystemd();

        if ($hasSystemd) {
            $servicePath = '/etc/systemd/system/mailpit.service';
            $serviceFile = VALET_ROOT_PATH.'/cli/stubs/init/mailpit';
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
     */
    private function updateDomain(): void
    {
        $domain = Configuration::get('domain');

        Site::proxyCreate("mails.$domain", 'http://localhost:8025', true);
    }

    /**
     * @return bool
     */
    private function isAvailable(): bool
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
}
