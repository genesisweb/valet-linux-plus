<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\DevTools;
use Valet\Filesystem;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\Site;
use Valet\Tests\TestCase;

use function Valet\group;
use function Valet\swap;
use function Valet\user;

class PhpFpmTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Configuration|MockObject $config;
    private Site|MockObject $site;
    private Nginx|MockObject $nginx;
    private PhpFpm $phpFpm;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Configuration::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->site = Mockery::mock(Site::class);
        $this->nginx = Mockery::mock(Nginx::class);

        $this->phpFpm = new PhpFpm(
            $this->config,
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem,
            $this->site,
            $this->nginx
        );
    }

    public function versionProvider(): array
    {
        return [
            [
                '8.2',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                '82',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                'php-8.2',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                'php8.2',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                'php@8.2',
                '8.2',
                'valet82.sock',
                '8.0',
            ],
            [
                '8.3',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
            [
                '83',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
            [
                'php-8.3',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
            [
                'php8.3',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
            [
                'php@8.3',
                '8.3',
                'valet83.sock',
                '8.0',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider versionProvider
     */
    public function itWillInstallSuccessfully(
        string $version,
        string $expectedVersion,
        string $expectedSocketFileName
    ): void {
        $fpmName = \sprintf('php%s-fpm', $expectedVersion);
        $prefix = \sprintf('php%s-', $expectedVersion);
        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->times(3)
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->packageManager
            ->shouldReceive('installed')
            ->once()
            ->with($fpmName)
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with($fpmName)
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('getPhpExtensionPrefix')
            ->once()
            ->with($expectedVersion)
            ->andReturn($prefix);

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with(
                \sprintf(
                    '%1$scli %1$smysql %1$sgd %1$szip %1$sxml %1$scurl %1$smbstring %1$spgsql %1$sintl %1$sposix',
                    $prefix
                )
            );

        $this->serviceManager
            ->shouldReceive('enable')
            ->once()
            ->with($fpmName);

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/var/log', user());

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH.'/cli/stubs/fpm.conf')
            ->andReturn('fpm.conf content VALET_USER VALET_GROUP VALET_FPM_SOCKET_FILE');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(
                '/etc/php/'.$expectedVersion.'/fpm/pool.d/valet.conf',
                \sprintf('fpm.conf content %s %s %s', user(), group(), VALET_HOME_PATH.'/'.$expectedSocketFileName),
            )
            ->andReturn('fpm.conf content VALET_USER VALET_GROUP VALET_FPM_SOCKET_FILE');

        $this->serviceManager
            ->shouldReceive('restart')
            ->once()
            ->with($fpmName);

        $this->phpFpm->install($version);
    }

    /**
     * @test
     * @dataProvider versionProvider
     */
    public function itWillUninstallSuccessfully(string $version, string $expectedVersion): void
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->with('/etc/php/'.$expectedVersion.'/fpm/pool.d/valet.conf')
            ->once()
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('unlink')
            ->with('/etc/php/'.$expectedVersion.'/fpm/pool.d/valet.conf')
            ->once();

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->with($expectedVersion)
            ->once()
            ->andReturn('php'.$expectedVersion.'-fpm');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('php'.$expectedVersion.'-fpm')
            ->once();

        $this->phpFpm->uninstall($version);
    }

    /**
     * @test
     * @dataProvider versionProvider
     */
    public function itWillSwitchVersionSuccessfully(
        string $version,
        string $expectedVersion,
        string $expectedSocketFileName,
        string $currentVersion
    ): void {
        $this->config
            ->shouldReceive('get')
            ->with('php_version', PHP_MAJOR_VERSION. '.'.PHP_MINOR_VERSION)
            ->once()
            ->andReturn($currentVersion);

        $fpmName = \sprintf('php%s-fpm', $expectedVersion);
        $prefix = \sprintf('php%s-', $expectedVersion);
        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->times(6)
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->packageManager
            ->shouldReceive('installed')
            ->once()
            ->with($fpmName)
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with($fpmName)
            ->andReturnFalse();

        $this->packageManager
            ->shouldReceive('getPhpExtensionPrefix')
            ->once()
            ->with($expectedVersion)
            ->andReturn($prefix);

        $this->packageManager
            ->shouldReceive('ensureInstalled')
            ->once()
            ->with(
                \sprintf(
                    '%1$scli %1$smysql %1$sgd %1$szip %1$sxml %1$scurl %1$smbstring %1$spgsql %1$sintl %1$sposix',
                    $prefix
                )
            );

        $this->serviceManager
            ->shouldReceive('enable')
            ->once()
            ->with($fpmName);

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/var/log', user());

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH.'/cli/stubs/fpm.conf')
            ->andReturn('fpm.conf content VALET_USER VALET_GROUP VALET_FPM_SOCKET_FILE');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(
                '/etc/php/'.$expectedVersion.'/fpm/pool.d/valet.conf',
                \sprintf('fpm.conf content %s %s %s', user(), group(), VALET_HOME_PATH.'/'.$expectedSocketFileName),
            )
            ->andReturn('fpm.conf content VALET_USER VALET_GROUP VALET_FPM_SOCKET_FILE');

        $this->serviceManager
            ->shouldReceive('restart')
            ->once()
            ->with($fpmName);

        $this->serviceManager
            ->expects('disabled')
            ->once()
            ->with($fpmName)
            ->andReturnTrue();

        $this->serviceManager
            ->expects('enable')
            ->once()
            ->with($fpmName);

        $this->config
            ->shouldReceive('set')
            ->once()
            ->with('php_version', $expectedVersion);

        $configuredSites = [
            'mails.test' => [
                'socket_file' => 'valet74.sock',
            ],
            'site1.test' => [
                'socket_file' => 'valet73.sock',
            ],
            'valetsite.test' => [
                'socket_file' => 'valet70.sock',
            ],
        ];
        $this->nginx
            ->shouldReceive('configuredSites')
            ->twice()
            ->andReturn(collect(array_keys($configuredSites)));

        foreach($configuredSites as $configuredSite => $siteData) {
            $this->filesystem
                ->shouldReceive('get')
                ->twice()
                ->with(VALET_HOME_PATH.'/Nginx/'.$configuredSite)
                ->andReturn('content unix:'.$siteData['socket_file']);

            $this->filesystem
                ->shouldReceive('put')
                ->once()
                ->with(VALET_HOME_PATH.'/Nginx/'.$configuredSite, 'content unix:'.VALET_HOME_PATH.'/'.$expectedSocketFileName);
        }

        $this->config
            ->shouldReceive('get')
            ->with('php_version', PHP_MAJOR_VERSION. '.'.PHP_MINOR_VERSION)
            ->once()
            ->andReturn($expectedVersion);

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->with($currentVersion)
            ->once()
            ->andReturn('php'.$currentVersion.'-fpm');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('php'.$currentVersion.'-fpm')
            ->once();

        $this->nginx
            ->shouldReceive('installServer')
            ->once()
            ->andReturn($expectedVersion);

        $this->nginx
            ->shouldReceive('restart')
            ->once();

        $this->serviceManager
            ->shouldReceive('printStatus')
            ->with($fpmName)
            ->once();

        $this->commandLine
            ->shouldReceive('run')
            ->with('update-alternatives --set php /usr/bin/php'.$expectedVersion)
            ->once();

        $this->phpFpm->switchVersion($version, true);
    }

    /**
     * @test
     * @dataProvider versionProvider
     */
    public function itWillRestartSuccessfully(string $version, string $expectedVersion): void
    {
        $fpmName = 'php'.$expectedVersion.'-fpm';

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->serviceManager
            ->shouldReceive('restart')
            ->with($fpmName)
            ->once();

        $this->phpFpm->restart($expectedVersion);
    }

    /**
     * @test
     * @dataProvider versionProvider
     */
    public function itWillStopSuccessfully(string $version, string $expectedVersion): void
    {
        $fpmName = 'php'.$expectedVersion.'-fpm';

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->serviceManager
            ->shouldReceive('stop')
            ->with($fpmName)
            ->once();

        $this->phpFpm->stop($expectedVersion);
    }

    /**
     * @test
     * @dataProvider versionProvider
     */
    public function itWillGetStatusSuccessfully(string $version, string $expectedVersion): void
    {
        $fpmName = 'php'.$expectedVersion.'-fpm';

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->with($expectedVersion)
            ->andReturn($fpmName);

        $this->serviceManager
            ->shouldReceive('printStatus')
            ->with($fpmName)
            ->once();

        $this->phpFpm->status($expectedVersion);
    }

    /**
     * @test
     */
    public function itWillIsolateDirectory(): void
    {
        $this->site
            ->shouldReceive('getSiteUrl')
            ->with('site')
            ->once()
            ->andReturn('site.test');

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->with('7.2')
            ->once()
            ->andReturn('php7.2-fpm');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('php7.2-fpm')
            ->once()
            ->andReturnTrue();

        $this->site
            ->shouldReceive('customPhpVersion')
            ->with('site.test')
            ->once()
            ->andReturnNull();

        $this->site
            ->shouldReceive('isolate')
            ->with('site.test', '7.2', true)
            ->once()
            ->andReturnNull();

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->with('7.2')
            ->once()
            ->andReturn('php7.2-fpm');

        $this->serviceManager
            ->shouldReceive('restart')
            ->with('php7.2-fpm')
            ->once();

        $this->nginx
            ->shouldReceive('restart')
            ->withNoArgs()
            ->once();

        $this->config
            ->shouldReceive('get')
            ->with('domain')
            ->once()
            ->andReturn('test');

        $devTools = Mockery::mock(DevTools::class);
        swap(DevTools::class, $devTools);

        $devTools->shouldReceive('getBin')
            ->with('php7.2', ['/usr/local/bin/php'])
            ->once()
            ->andReturn('/usr/bin/php7.2');

        $this->config
            ->shouldReceive('get')
            ->with('isolated_versions', [])
            ->once()
            ->andReturn([]);

        $this->config
            ->shouldReceive('set')
            ->with('isolated_versions', ['site' => '/usr/bin/php7.2'])
            ->once();

        $return = $this->phpFpm->isolateDirectory('site', '7.2', true);

        $this->assertTrue($return);
    }

    /**
     * @test
     */
    public function itWillThrowExceptionWhenInvalidSiteSelected(): void
    {
        Writer::fake();

        $this->site
            ->shouldReceive('getSiteUrl')
            ->with('site')
            ->once()
            ->andThrows(new \DomainException('invalid-directory'));

        $this->packageManager
            ->shouldNotReceive('getPhpFpmName');

        $this->packageManager
            ->shouldNotReceive('installed');

        $this->site
            ->shouldNotReceive('customPhpVersion');

        $this->site
            ->shouldNotReceive('isolate');

        $this->serviceManager
            ->shouldNotReceive('restart');

        $this->nginx
            ->shouldNotReceive('restart');

        $this->config
            ->shouldNotReceive('get');

        $this->config
            ->shouldNotReceive('set');

        $return = $this->phpFpm->isolateDirectory('site', '7.2', true);

        $this->assertFalse($return);

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('invalid-directory', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillThrowExceptionWhenInvalidVersionGiven(): void
    {
        Writer::fake();

        $this->site
            ->shouldReceive('getSiteUrl')
            ->with('site')
            ->once()
            ->andReturn('site.test');

        $this->packageManager
            ->shouldNotReceive('getPhpFpmName');

        $this->packageManager
            ->shouldNotReceive('installed');

        $this->site
            ->shouldNotReceive('customPhpVersion');

        $this->site
            ->shouldNotReceive('isolate');

        $this->serviceManager
            ->shouldNotReceive('restart');

        $this->nginx
            ->shouldNotReceive('restart');

        $this->config
            ->shouldNotReceive('get');

        $this->config
            ->shouldNotReceive('set');

        $return = $this->phpFpm->isolateDirectory('site', '5.6', true);

        $this->assertFalse($return);

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('Invalid version [5.6] used. Supported versions are', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillUnIsolateDirectory(): void
    {
        $this->site
            ->shouldReceive('getSiteUrl')
            ->with('site')
            ->once()
            ->andReturn('site.test');

        $this->site
            ->shouldReceive('customPhpVersion')
            ->with('site.test')
            ->once()
            ->andReturnNull();

        $this->site
            ->shouldReceive('removeIsolation')
            ->with('site.test')
            ->once()
            ->andReturnNull();

        $this->nginx
            ->shouldReceive('restart')
            ->withNoArgs()
            ->once();

        $this->config
            ->shouldReceive('get')
            ->with('domain')
            ->once()
            ->andReturn('test');

        $this->config
            ->shouldReceive('get')
            ->with('isolated_versions', [])
            ->once()
            ->andReturn(['site' => '/usr/bin/php7.2']);

        $this->config
            ->shouldReceive('set')
            ->with('isolated_versions', [])
            ->once();

        $this->phpFpm->unIsolateDirectory('site');
    }

    /**
     * @test
     */
    public function itWillListIsolatedDirectories(): void
    {
        $sites = collect([
            'fpm-site.test',
            'second.test',
        ]);
        $this->nginx
            ->shouldReceive('configuredSites')
            ->withNoArgs()
            ->once()
            ->andReturn($sites);

        foreach ($sites->toArray() as $site) {
            $this->filesystem
                ->shouldReceive('get')
                ->with(VALET_HOME_PATH.'/Nginx/'.$site)
                ->once()
                ->andReturn('nginx-content ISOLATED_PHP_VERSION');

            $this->site
                ->shouldReceive('customPhpVersion')
                ->with($site)
                ->once()
                ->andReturn('7.2');
        }

        $output = $this->phpFpm->isolatedDirectories();

        $this->assertSame(
            [
                [
                    'url' => 'fpm-site.test',
                    'version' => '7.2',
                ],
                [
                    'url' => 'second.test',
                    'version' => '7.2',
                ],
            ],
            $output->toArray()
        );
    }

    public function socketFileVersionProvider(): array
    {
        return [
            [
                '8.2',
                'valet82.sock',
            ],
            [
                '8.1',
                'valet81.sock',
            ],
            [
                '8.3',
                'valet83.sock',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider socketFileVersionProvider
     */
    public function itWillGetSocketFileName(string $version, string $expectedSocket): void
    {
        $socketFile = $this->phpFpm->socketFileName($version);

        $this->assertSame($expectedSocket, $socketFile);
    }

    /**
     * @test
     * @dataProvider versionProvider
     */
    public function itWillNormalizePhpVersion(string $phpVersion, string $expectedVersion): void
    {
        $normalizedVersion = $this->phpFpm->normalizePhpVersion($phpVersion);

        $this->assertSame($expectedVersion, $normalizedVersion);
    }

    public function invalidVersionProvider(): array
    {
        return [
            [
                'invalid',
            ],
            [
                '8',
            ],
            [
                'invalid-8.0',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider invalidVersionProvider
     */
    public function itWillReturnBlankStringWhenInvalidStringGiven(string $version): void
    {
        $normalizedVersion = $this->phpFpm->normalizePhpVersion($version);

        $this->assertSame('', $normalizedVersion);
    }

    public function executableVersionProvider(): array
    {
        return [
            [
                '8.1',
                'valet81.sock',
            ],
            [
                '8.2',
                'valet82.sock',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider executableVersionProvider
     */
    public function itWillGetExecutablePath(string $version): void
    {
        $devTools = Mockery::mock(DevTools::class);
        swap(DevTools::class, $devTools);

        $devTools->shouldReceive('getBin')
            ->with('php'.$version, ['/usr/local/bin/php'])
            ->once()
            ->andReturn('/usr/bin/php'.$version);

        $binFile = $this->phpFpm->getPhpExecutablePath($version);

        $this->assertSame('/usr/bin/php'.$version, $binFile);
    }

    /**
     * @test
     * @dataProvider executableVersionProvider
     */
    public function itWillGetFpmSocketFile(string $version, string $expectedSocketFile): void
    {
        $socketFile = $this->phpFpm->fpmSocketFile($version);

        $this->assertSame(VALET_HOME_PATH.'/'.$expectedSocketFile, $socketFile);
    }

    public function versionDataProvider(): array
    {
        return [
            [
                '8.2',
            ],
            [
                '8.3',
            ]
        ];
    }

    /**
     * @test
     * @dataProvider versionDataProvider
     */
    public function itWillValidateVersion(string $version): void
    {
        $isValid = $this->phpFpm->validateVersion($version);

        $this->assertTrue($isValid);
    }

    public function deprecatedVersionDataProvider(): array
    {
        return [
            [
                '7.0',
            ],
            [
                '7.1',
            ],
            [
                '7.2',
            ],
            [
                '7.3',
            ],
            [
                '7.4',
            ],
            [
                '8.0',
            ],
            [
                '8.1',
            ],
        ];
    }

    /**
     * @test
     * @dataProvider deprecatedVersionDataProvider
     */
    public function itWillValidateDeprecatedVersion(string $version): void
    {
        $isValid = $this->phpFpm->validateVersion($version);

        $this->assertFalse($isValid);
    }
}
