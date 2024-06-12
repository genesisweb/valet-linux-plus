<?php

namespace Valet;

use ConsoleComponents\Writer;
use Exception;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class DnsMasq
{
    public PackageManager $pm;
    public ServiceManager $sm;
    public CommandLine $cli;
    public Filesystem $files;
    public string $rclocal = '/etc/rc.local';
    public string $resolvconf = '/etc/resolv.conf';
    public string $dnsmasqconf = '/etc/dnsmasq.conf';
    public string $dnsmasqOpts = '/etc/dnsmasq.d/options';
    public string $resolvedConfigPath = '/etc/systemd/resolved.conf';
    public string $configPath = '/etc/dnsmasq.d/valet';
    public string $nmConfigPath = '/etc/NetworkManager/conf.d/valet.conf';

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
    public function install(string $domain): void
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

        $this->lockResolvConf();
        $this->files->restore($this->rclocal);

        $this->cli->passthru('rm -f /etc/resolv.conf');
        $this->sm->stop('systemd-resolved');
        $this->sm->start('systemd-resolved');
        $this->files->symlink('/run/systemd/resolve/resolv.conf', $this->resolvconf);

        $this->files->restore($this->dnsmasqconf);
        $this->files->commentLine('IGNORE_RESOLVCONF', '/etc/default/dnsmasq');

        $this->pm->restartNetworkManager();
        $this->sm->restart('dnsmasq');

        Writer::info('Valet DNS changes have been rolled back');
    }

    /**
     * Install and configure DnsMasq.
     */
    private function lockResolvConf(): void
    {
        if (!$this->files->isLink($this->resolvconf)) {
            $this->cli->run(
                "chattr -i $this->resolvconf",
                function ($code, $msg) {
                    Writer::warn($msg);
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
            'address=/.' . $domain . '/127.0.0.1' . PHP_EOL . 'server=1.1.1.1' . PHP_EOL . 'server=8.8.8.8' . PHP_EOL
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

        $this->lockResolvConf();
        $this->mergeDns();

        $this->files->unlink('/etc/dnsmasq.d/network-manager');
        $this->files->backup($this->dnsmasqconf);

        $this->files->putAsUser(
            $this->dnsmasqconf,
            $this->files->get(VALET_ROOT_PATH . '/cli/stubs/dnsmasq.conf')
        );
        $this->files->putAsUser(
            $this->dnsmasqOpts,
            $this->files->get(VALET_ROOT_PATH . '/cli/stubs/dnsmasq_options')
        );
        $this->files->putAsUser(
            $this->nmConfigPath,
            $this->files->get(VALET_ROOT_PATH . '/cli/stubs/networkmanager.conf')
        );
    }
}
