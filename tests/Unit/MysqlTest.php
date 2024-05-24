<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Filesystem;
use Valet\Mysql;
use Valet\PhpFpm;
use Valet\Tests\TestCase;

use function Valet\swap;

class MysqlTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private Mysql $mysql;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->config = Mockery::mock(Configuration::class);

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mariadb-server')
            ->once()
            ->andReturnFalse();

        $this->mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );
    }

    public function packageDataProvider(): array
    {
        return [
            'mysql' => [
                false,
                'mysql',
                'mysql-server',
            ],
            'mariadb' => [
                true,
                'mariadb',
                'mariadb-server',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider packageDataProvider
     */
    public function itWillInstallSuccessfully(bool $useMariaDB, string $packageName, string $packageServerName): void
    {
        Writer::fake();
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);
        $phpFpm->shouldReceive('getCurrentVersion')->once()->andReturn('8.2');

        $this->packageManager
            ->shouldReceive('packageName')
            ->with($packageName)
            ->once()
            ->andReturn($packageServerName);

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->with('php8.2-mysql')
            ->once();

        $this->packageManager
            ->shouldReceive('installed')
            ->with($packageServerName)
            ->once()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('installOrFail')
            ->with($packageServerName)
            ->once()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->twice()
            ->andReturn('mariadb-server');

        $this->serviceManager
            ->shouldReceive('enable')
            ->with($packageName)
            ->once()
            ->andReturnFalse();

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturn('');

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn([]);

        $this->config
            ->shouldReceive('set')
            ->with('mysql', ['user' => 'valet', 'password' => ''])
            ->once()
            ->andReturn([]);

        $this->mysql->install($useMariaDB);
    }

    /**
     * @test
     */
    public function itWillNotOverrideWhenInstalled()
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn(['user' => 'valet', 'password' => 'valet-password']);

        $this->packageManager
            ->shouldNotReceive('installOrFail')
            ->with('mysql-server');
        $this->serviceManager
            ->shouldNotReceive('enable')
            ->with('mysql');

        $mysql->install();
    }

    /**
     * @test
     */
    public function itWillNotOverrideWhenMariaDbInstalled()
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mariadb-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mariadb-server')
            ->once()
            ->andReturnTrue();

        $this->config
            ->shouldReceive('get')
            ->with('mysql', [])
            ->once()
            ->andReturn(['user' => 'valet', 'password' => 'valet-password']);

        $this->packageManager
            ->shouldNotReceive('installOrFail')
            ->with('mariadb-server');
        $this->serviceManager
            ->shouldNotReceive('enable')
            ->with('mariadb');

        $mysql->install(true);
    }

    /**
     * @test
     */
    public function itWillStopService(): void
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $mysql->stop();
    }

    /**
     * @test
     */
    public function itWillRestartService(): void
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->serviceManager
            ->shouldReceive('restart')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $mysql->restart();
    }

    /**
     * @test
     */
    public function itWillUninstallService(): void
    {
        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mysql')
            ->once()
            ->andReturn('mysql-server');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('mysql-server')
            ->once()
            ->andReturnTrue();

        $mysql = new Mysql(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->config
        );

        $this->packageManager
            ->shouldReceive('packageName')
            ->with('mariadb')
            ->once()
            ->andReturn('mariadb-server');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('mysql')
            ->once()
            ->andReturnTrue();

        $mysql->uninstall();
    }
}
