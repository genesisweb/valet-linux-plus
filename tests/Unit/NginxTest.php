<?php

namespace Valet\Tests\Unit;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\Site;
use Valet\Tests\TestCase;

use function Valet\swap;

class NginxTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private Site|MockObject $site;
    private Nginx $nginx;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->site = Mockery::mock(Site::class);

        $this->nginx = new Nginx(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config,
            $this->site
        );
    }

    /**
     * @test
     */
    public function itWillInstallSuccessfully(): void
    {
        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with('nginx');

        $this->serviceManager
            ->shouldReceive('enable')
            ->once()
            ->with('nginx');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('apache2')
            ->once()
            ->andReturnTrue();

        $this->serviceManager
            ->shouldReceive('disabled')
            ->once()
            ->with('apache2')
            ->andReturnFalse();

        $this->serviceManager
            ->shouldReceive('disable')
            ->once()
            ->with('apache2');

        $this->serviceManager
            ->shouldReceive('stop')
            ->once()
            ->with('apache2');

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/etc/nginx/sites-available');

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/etc/nginx/sites-enabled');

        $this->serviceManager
            ->shouldReceive('stop')
            ->once()
            ->with('nginx');

        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/nginx.conf')
            ->once()
            ->andReturn('nginx.conf content');

        $this->commandLine
            ->shouldReceive('run')
            ->with('cat /lib/systemd/system/nginx.service')
            ->once()
            ->andReturn('pid /run/nginx.pid');

        $this->filesystem
            ->shouldReceive('backup')
            ->with('/etc/nginx/nginx.conf')
            ->once();

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with('/etc/nginx/nginx.conf', 'nginx.conf content')
            ->once();

        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/valet.conf')
            ->once()
            ->andReturn('valet.conf content');

        $this->config
            ->shouldReceive('get')
            ->with('port')
            ->once()
            ->andReturn('80');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with('/etc/nginx/sites-available/valet.conf', 'valet.conf content')
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/etc/nginx/sites-enabled/default')
            ->once()
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('unlink')
            ->with('/etc/nginx/sites-enabled/default')
            ->once();

        $this->commandLine
            ->shouldReceive('run')
            ->with('ln -snf /etc/nginx/sites-available/valet.conf /etc/nginx/sites-enabled/valet.conf')
            ->once();

        $this->filesystem
            ->shouldReceive('backup')
            ->with('/etc/nginx/fastcgi_params')
            ->once();

        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/fastcgi_params')
            ->once()
            ->andReturn('fastcgi_params content');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with('/etc/nginx/fastcgi_params', 'fastcgi_params content')
            ->once();

        $this->filesystem
            ->shouldReceive('isDir')
            ->with(VALET_HOME_PATH.'/Nginx')
            ->once()
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('mkdirAsUser')
            ->with(VALET_HOME_PATH.'/Nginx')
            ->once();

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(VALET_HOME_PATH.'/Nginx/.keep', "\n")
            ->once();

        $this->config
            ->shouldReceive('get')
            ->with('domain')
            ->once()
            ->andReturn('test');

        $this->site
            ->shouldReceive('resecureForNewDomain')
            ->with('test', 'test')
            ->once();

        $this->nginx->install();
    }

    /**
     * @test
     */
    public function itWillUpdatePort(): void
    {
        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/valet.conf')
            ->once()
            ->andReturn('valet.conf content VALET_PORT');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with('/etc/nginx/sites-available/valet.conf', 'valet.conf content 88')
            ->once();

        $this->nginx->updatePort('88');
    }

    /**
     * @test
     */
    public function itWillRestartService(): void
    {
        $this->serviceManager
            ->shouldReceive('restart')
            ->with('nginx')
            ->once();

        $this->nginx->restart();
    }

    /**
     * @test
     */
    public function itWillStopService(): void
    {
        $this->serviceManager
            ->shouldReceive('stop')
            ->with('nginx')
            ->once();

        $this->nginx->stop();
    }

    /**
     * @test
     */
    public function itWillPrintStatusOfService(): void
    {
        $this->serviceManager
            ->shouldReceive('printStatus')
            ->with('nginx')
            ->once();

        $this->nginx->status();
    }

    /**
     * @test
     */
    public function itWillUninstall(): void
    {
        $this->serviceManager
            ->shouldReceive('stop')
            ->with('nginx')
            ->once();

        $this->filesystem
            ->shouldReceive('restore')
            ->with('/etc/nginx/nginx.conf')
            ->once();

        $this->filesystem
            ->shouldReceive('restore')
            ->with('/etc/nginx/fastcgi_params')
            ->once();

        $this->filesystem
            ->shouldReceive('unlink')
            ->with('/etc/nginx/sites-enabled/valet.conf')
            ->once();

        $this->filesystem
            ->shouldReceive('unlink')
            ->with('/etc/nginx/sites-available/valet.conf')
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/etc/nginx/sites-available/default')
            ->once()
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('symlink')
            ->with('/etc/nginx/sites-available/default', '/etc/nginx/sites-enabled/default')
            ->once();

        $this->nginx->uninstall();
    }

    /**
     * @test
     */
    public function itWillListConfiguredSites(): void
    {
        $this->filesystem
            ->shouldReceive('scandir')
            ->with(VALET_HOME_PATH.'/Nginx')
            ->once()
            ->andReturn(['site1', 'site2', 'site3', '.hiddenSite']);

        $output = $this->nginx->configuredSites();

        $this->assertSame(['site1', 'site2', 'site3'], $output->all());
    }

    /**
     * @test
     */
    public function itWillInstallServerByGivenPhpVersion(): void
    {
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);

        $phpFpm->shouldReceive('socketFileName')
            ->with('8.2')
            ->once()
            ->andReturn('valet82.sock');

        $this->config
            ->shouldReceive('get')
            ->with('port')
            ->once()
            ->andReturn('80');

        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/valet.conf')
            ->once()
            ->andReturn('valet.conf content VALET_FPM_SOCKET_FILE VALET_PORT');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                '/etc/nginx/sites-available/valet.conf',
                \sprintf('valet.conf content %s 80', VALET_HOME_PATH.'/valet82.sock')
            )
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->with('/etc/nginx/sites-enabled/default')
            ->once()
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('unlink')
            ->with('/etc/nginx/sites-enabled/default')
            ->once();

        $this->commandLine
            ->shouldReceive('run')
            ->with('ln -snf /etc/nginx/sites-available/valet.conf /etc/nginx/sites-enabled/valet.conf')
            ->once();

        $this->filesystem
            ->shouldReceive('backup')
            ->with('/etc/nginx/fastcgi_params')
            ->once();

        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/fastcgi_params')
            ->once()
            ->andReturn('fastcgi_params content');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with('/etc/nginx/fastcgi_params', 'fastcgi_params content')
            ->once();

        $this->nginx->installServer('8.2');
    }
}
