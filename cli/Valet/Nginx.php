<?php

namespace Valet;

use Tightenco\Collect\Support\Collection;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\PhpFpm as PhpFpmFacade;

class Nginx
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
     * @var Configuration
     */
    public $configuration;
    /**
     * @var Site
     */
    public $site;
    /**
     * @var string
     */
    const NGINX_CONF = '/etc/nginx/nginx.conf';
    const SITES_AVAILABLE_CONF = '/etc/nginx/sites-available/valet.conf';
    const SITES_ENABLED_CONF = '/etc/nginx/sites-enabled/valet.conf';

    /**
     * Create a new Nginx instance.
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     * @param Filesystem     $files
     * @param Configuration  $configuration
     * @param Site           $site
     *
     * @return void
     */
    public function __construct(
        PackageManager $pm,
        ServiceManager $sm,
        CommandLine    $cli,
        Filesystem     $files,
        Configuration  $configuration,
        Site           $site
    ) {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->site = $site;
        $this->files = $files;
        $this->configuration = $configuration;
    }

    /**
     * Install the configuration files for Nginx.
     */
    public function install(): void
    {
        $this->pm->ensureInstalled('nginx');
        $this->sm->enable('nginx');
        $this->handleApacheService();
        $this->files->ensureDirExists('/etc/nginx/sites-available');
        $this->files->ensureDirExists('/etc/nginx/sites-enabled');

        $this->stop();
        $this->installConfiguration();
        $this->installServer();
        $this->installNginxDirectory();
    }

    /**
     * Update the port used by Nginx.
     */
    public function updatePort(string $newPort): void
    {
        $valetConfig = strArrayReplace([
            'VALET_HOME_PATH'   => VALET_HOME_PATH,
            'VALET_SERVER_PATH' => VALET_SERVER_PATH,
            'VALET_PORT'        => $newPort,
        ], $this->files->get(__DIR__.'/../stubs/valet.conf'));
        $this->files->putAsUser(self::SITES_AVAILABLE_CONF, $valetConfig);
    }

    /**
     * Restart the Nginx service.
     */
    public function restart(): void
    {
        $this->sm->restart('nginx');
    }

    /**
     * Stop the Nginx service.
     */
    public function stop(): void
    {
        $this->sm->stop('nginx');
    }

    /**
     * Nginx service status.
     */
    public function status(): void
    {
        $this->sm->printStatus('nginx');
    }

    /**
     * Prepare Nginx for uninstall.
     */
    public function uninstall(): void
    {
        $this->stop();
        $this->files->restore(self::NGINX_CONF);
        $this->files->restore('/etc/nginx/fastcgi_params');
        $this->files->unlink(self::SITES_ENABLED_CONF);
        $this->files->unlink(self::SITES_AVAILABLE_CONF);

        if ($this->files->exists('/etc/nginx/sites-available/default')) {
            $this->files->symlink('/etc/nginx/sites-available/default', '/etc/nginx/sites-enabled/default');
        }
    }

    /**
     * Return a list of all sites with explicit Nginx configurations.
     */
    public function configuredSites(): Collection
    {
        return collect($this->files->scandir(VALET_HOME_PATH.'/Nginx'))
            ->reject(function ($file) {
                return startsWith($file, '.');
            });
    }

    /**
     * Install the Valet Nginx server configuration file.
     *
     * @param string|float|null $phpVersion
     */
    public function installServer($phpVersion = null): void
    {
        $valetConf = strArrayReplace([
            'VALET_HOME_PATH'       => VALET_HOME_PATH,
            'VALET_FPM_SOCKET_FILE' => VALET_HOME_PATH.'/'.PhpFpmFacade::socketFileName($phpVersion),
            'VALET_SERVER_PATH'     => VALET_SERVER_PATH,
            'VALET_STATIC_PREFIX'   => VALET_STATIC_PREFIX,
            'VALET_PORT'            => $this->configuration->read()['port'],
        ], $this->files->get(__DIR__.'/../stubs/valet.conf'));
        $this->files->putAsUser(self::SITES_AVAILABLE_CONF, $valetConf);

        if ($this->files->exists('/etc/nginx/sites-enabled/default')) {
            $this->files->unlink('/etc/nginx/sites-enabled/default');
        }

        $this->cli->run(\sprintf("ln -snf %s %s", self::SITES_AVAILABLE_CONF, self::SITES_ENABLED_CONF));
        $this->files->backup('/etc/nginx/fastcgi_params');

        $this->files->putAsUser(
            '/etc/nginx/fastcgi_params',
            $this->files->get(__DIR__.'/../stubs/fastcgi_params')
        );
    }

    /**
     * Generate fresh Nginx servers for existing secure sites.
     */
    private function rewriteSecureNginxFiles(): void
    {
        $domain = $this->configuration->read()['domain'];

        $this->site->resecureForNewDomain($domain, $domain);
    }

    /**
     * Disable Apache2 Service.
     */
    private function handleApacheService(): void
    {
        if (!$this->pm->installed('apache2')) {
            return;
        }
        if (!$this->sm->disabled('apache2')) {
            $this->sm->disable('apache2');
        }
        $this->sm->stop('apache2');
    }

    /**
     * Install the Nginx configuration file.
     */
    private function installConfiguration(): void
    {
        $contents = $this->files->get(__DIR__.'/../stubs/nginx.conf');
        $nginxConfig = self::NGINX_CONF;

        $pidPath = 'pid /run/nginx.pid';
        $hasPIDOption = strpos($this->cli->run('cat /lib/systemd/system/nginx.service'), 'pid /');

        if ($hasPIDOption) {
            $pidPath = '# pid /run/nginx.pid';
        }

        $this->files->backup($nginxConfig);

        $this->files->putAsUser(
            $nginxConfig,
            strArrayReplace([
                'VALET_USER'      => user(),
                'VALET_GROUP'     => group(),
                'VALET_HOME_PATH' => VALET_HOME_PATH,
                'VALET_PID'       => $pidPath,
            ], $contents)
        );
    }

    /**
     * Install the Nginx configuration directory to the ~/.valet directory.
     *
     * This directory contains all site-specific Nginx servers.
     */
    private function installNginxDirectory(): void
    {
        if (!$this->files->isDir($nginxDirectory = VALET_HOME_PATH.'/Nginx')) {
            $this->files->mkdirAsUser($nginxDirectory);
        }

        $this->files->putAsUser($nginxDirectory.'/.keep', "\n");

        $this->rewriteSecureNginxFiles();
    }
}
