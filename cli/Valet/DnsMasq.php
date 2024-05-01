<?php

namespace Valet;

use Exception;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class DnsMasq
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
    public $rclocal = '/etc/rc.local';
    /**
     * @var string
     */
    public $resolvconf = '/etc/resolv.conf';
    /**
     * @var string
     */
    public $dnsmasqconf = '/etc/dnsmasq.conf';
    /**
     * @var string
     */
    public $dnsmasqOpts = '/etc/dnsmasq.d/options';
    /**
     * @var string
     */
    public $resolvedConfigPath = '/etc/systemd/resolved.conf';
    /**
     * @var string
     */
    public $configPath = '/etc/dnsmasq.d/valet';
    /**
     * @var string
     */
    public $nmConfigPath = '/etc/NetworkManager/conf.d/valet.conf';

    /**
     * Create a new DnsMasq instance.
     */
    public function __construct(PackageManager $pm, ServiceManager $sm, Filesystem $files, CommandLine $cli)
    {
        $this->pm = $pm;
        $this->sm = $sm;
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Install and configure DnsMasq.
     *
     * @throws Exception
     */
    public function install(string $domain = 'test'): void
    {
        $this->dnsmasqSetup();
        $this->stopResolved();
        $this->createCustomConfigFile($domain);
        $this->sm->restart('dnsmasq');
    }

    /**
     * Stop the DnsMasq service.
     */
    public function stop(): void
    {
        $this->sm->stop('dnsmasq');
    }

    /**
     * Restart the DnsMasq service.
     */
    public function restart(): void
    {
        $this->sm->restart('dnsmasq');
    }

    /**
     * Update the domain used by DnsMasq.
     */
    public function updateDomain(string $newDomain): void
    {
        $this->createCustomConfigFile($newDomain);
        $this->sm->restart('dnsmasq');
    }

    /**
     * Delete the DnsMasq config file.
     */
    public function uninstall(): void
    {
        $this->sm->removeValetDns();

        $this->cli->passthru('rm -rf /opt/valet-linux');
        $this->files->unlink($this->configPath);
        $this->files->unlink($this->dnsmasqOpts);
        $this->files->unlink($this->nmConfigPath);
        $this->files->restore($this->resolvedConfigPath);

        $this->lockResolvConf(false);
        $this->files->restore($this->rclocal);

        $this->cli->passthru('rm -f /etc/resolv.conf');
        $this->sm->stop('systemd-resolved');
        $this->sm->start('systemd-resolved');
        $this->files->symlink('/run/systemd/resolve/resolv.conf', $this->resolvconf);

        $this->files->restore($this->dnsmasqconf);
        $this->files->commentLine('IGNORE_RESOLVCONF', '/etc/default/dnsmasq');

        $this->pm->restartNetworkManager();
        $this->sm->restart('dnsmasq');

        info('Valet DNS changes have been rolled back');
    }

    /**
     * Install and configure DnsMasq.
     */
    private function lockResolvConf(bool $lock = true): void
    {
        $arg = $lock ? '+i' : '-i';

        if (!$this->files->isLink($this->resolvconf)) {
            $this->cli->run(
                "chattr {$arg} {$this->resolvconf}",
                function ($code, $msg) {
                    warning($msg);
                }
            );
        }
    }

    /**
     * Enable nameserver merging.
     * @throws Exception
     */
    private function mergeDns(): void
    {
        $optDir = '/opt/valet-linux';
        $this->files->remove($optDir);
        $this->files->ensureDirExists($optDir);

        $this->sm->removeValetDns();

        if ($this->files->exists($this->rclocal)) {
            $this->files->restore($this->rclocal);
        }
    }

    /**
     * Append the custom DnsMasq configuration file to the main configuration file.
     */
    private function createCustomConfigFile(string $domain): void
    {
        $this->files->putAsUser(
            $this->configPath,
            'address=/.'.$domain.'/127.0.0.1'.PHP_EOL.'server=1.1.1.1'.PHP_EOL.'server=8.8.8.8'.PHP_EOL
        );
    }

    /**
     * Fix systemd-resolved configuration.
     */
    private function stopResolved(): void
    {
        if (!$this->sm->disabled('systemd-resolved')) {
            $this->sm->disable('systemd-resolved');
        }
        $this->sm->stop('systemd-resolved');
    }

    /**
     * Setup dnsmasq with Network Manager.
     * @throws Exception
     */
    private function dnsmasqSetup(): void
    {
        $this->pm->ensureInstalled('dnsmasq');
        $this->sm->enable('dnsmasq');

        $this->files->ensureDirExists('/etc/NetworkManager/conf.d');
        $this->files->ensureDirExists('/etc/dnsmasq.d');

        $this->files->uncommentLine('IGNORE_RESOLVCONF', '/etc/default/dnsmasq');

        $this->lockResolvConf(false);
        $this->mergeDns();

        $this->files->unlink('/etc/dnsmasq.d/network-manager');
        $this->files->backup($this->dnsmasqconf);

        $this->files->putAsUser($this->dnsmasqconf, $this->files->get(__DIR__.'/../stubs/dnsmasq.conf'));
        $this->files->putAsUser($this->dnsmasqOpts, $this->files->get(__DIR__.'/../stubs/dnsmasq_options'));
        $this->files->putAsUser($this->nmConfigPath, $this->files->get(__DIR__.'/../stubs/networkmanager.conf'));
    }
}
