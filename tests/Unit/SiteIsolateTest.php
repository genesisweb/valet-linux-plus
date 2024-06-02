<?php

namespace Unit;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\Configuration;
use Valet\Contracts\PackageManager;
use Valet\DevTools;
use Valet\Filesystem;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\Site;
use Valet\SiteIsolate;
use Valet\SiteSecure;
use Valet\Tests\TestCase;

use function Valet\swap;

class SiteIsolateTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private Configuration|MockObject $config;
    private Filesystem|MockObject $filesystem;
    private SiteSecure|MockObject $siteSecure;
    private Site|MockObject $site;
    private SiteIsolate $siteIsolate;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->config = Mockery::mock(Configuration::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->siteSecure = Mockery::mock(SiteSecure::class);
        $this->site = Mockery::mock(Site::class);

        $this->siteIsolate = new SiteIsolate(
            $this->packageManager,
            $this->config,
            $this->filesystem,
            $this->siteSecure,
            $this->site
        );
    }

    /**
     * @test
     */
    public function itWillIsolateDirectory(): void
    {
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);
        $nginx = Mockery::mock(Nginx::class);
        swap(Nginx::class, $nginx);
        $devTools = Mockery::mock(DevTools::class);
        swap(DevTools::class, $devTools);

        $this->site
            ->shouldReceive('getSiteUrl')
            ->with('site')
            ->once()
            ->andReturn('site.test');
        $phpFpm->shouldReceive('normalizePhpVersion')
            ->with('7.2')
            ->once()
            ->andReturn('7.2');

        $this->packageManager
            ->shouldReceive('getPhpFpmName')
            ->with('7.2')
            ->once()
            ->andReturn('php7.2-fpm');

        $this->packageManager
            ->shouldReceive('installed')
            ->with('php7.2-fpm')
            ->once()
            ->andReturnFalse();

        $phpFpm->shouldReceive('install')
            ->with('7.2')
            ->once();

        // Isolated PHP Version
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test')
            ->andReturn("# ISOLATED_PHP_VERSION=7.1\nNginx content");
        // Isolated PHP Version END

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/secure.isolated.valet.conf')
            ->andReturn('secure stub content');

        $phpFpm->shouldReceive('fpmSocketFile')
            ->with('7.2')
            ->once()
            ->andReturn('/var/run/php/php7.2-fpm.sock');

        $this->siteSecure
            ->shouldReceive('secure')
            ->once()
            ->with('site.test', 'secure stub content');

        $phpFpm->shouldReceive('stopIfUnused')
            ->with('7.1')
            ->once();

        $phpFpm->shouldReceive('restart')
            ->with('7.2')
            ->once();

        $nginx->shouldReceive('restart')
            ->withNoArgs()
            ->once();

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('domain')
            ->andReturn('test');

        $devTools->shouldReceive('getBin')
            ->once()
            ->with('php7.2', ['/usr/local/bin/php'])
            ->andReturn('/usr/bin/php7.2');

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('isolated_versions', [])
            ->andReturn([]);

        $this->config
            ->shouldReceive('set')
            ->once()
            ->with(
                'isolated_versions',
                [
                'site' => '/usr/bin/php7.2',
                ]
            );

        $return = $this->siteIsolate->isolateDirectory('site', '7.2', true);

        $this->assertTrue($return);
    }

    /**
     * @test
     */
    public function itWillUnIsolateDirectory(): void
    {
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);
        $nginx = Mockery::mock(Nginx::class);
        swap(Nginx::class, $nginx);

        $this->site
            ->shouldReceive('getSiteUrl')
            ->with('site')
            ->once()
            ->andReturn('site.test');

        // Isolated PHP Version
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test')
            ->andReturn("# ISOLATED_PHP_VERSION=7.2\nNginx content");
        // Isolated PHP Version END

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.crt')
            ->andReturnTrue();

        $this->siteSecure
            ->shouldReceive('buildSecureNginxServer')
            ->once()
            ->with('site.test')
            ->andReturn('site conf');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test', 'site conf');

        $phpFpm->shouldReceive('stopIfUnused')
            ->with('7.2')
            ->once();

        $nginx->shouldReceive('restart')
            ->withNoArgs()
            ->once();

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('domain')
            ->andReturn('test');

        $this->config
            ->shouldReceive('get')
            ->with('isolated_versions', [])
            ->once()
            ->andReturn(['site' => '/usr/local/bin/php7.2']);

        $this->config
            ->shouldReceive('set')
            ->with('isolated_versions', [])
            ->once();

        $this->siteIsolate->unIsolateDirectory('site');
    }

    /**
     * @test
     */
    public function itWillListIsolatedDirectories(): void
    {
        $nginx = Mockery::mock(Nginx::class);
        swap(Nginx::class, $nginx);
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);

        $this->siteSecure
            ->shouldReceive('secured')
            ->withNoArgs()
            ->once()
            ->andReturn(collect([
                'site1.test'
            ]));

        $dummySites = ['site1.test', 'site2.test'];
        $nginx->shouldReceive('configuredSites')
            ->withNoArgs()
            ->once()
            ->andReturn(collect($dummySites));

        foreach ($dummySites as $dummySite) {
            $this->filesystem
                ->shouldReceive('get')
                ->once()
                ->with(VALET_HOME_PATH . '/Nginx/' . $dummySite)
                ->andReturn('ISOLATED_PHP_VERSION');

            // Isolated PHP Version
            $this->filesystem
                ->shouldReceive('exists')
                ->once()
                ->with(VALET_HOME_PATH . '/Nginx/' . $dummySite)
                ->andReturnTrue();

            $this->filesystem
                ->shouldReceive('get')
                ->once()
                ->with(VALET_HOME_PATH . '/Nginx/' . $dummySite)
                ->andReturn("# ISOLATED_PHP_VERSION=7.2\nNginx content");
            // Isolated PHP Version END

            $phpFpm->shouldReceive('normalizePhpVersion')
                ->with('7.2')
                ->once()
                ->andReturn('7.2');
        }

        $response = $this->siteIsolate->isolatedDirectories();
        $this->assertSame([
            [
                'url' => 'https://site1.test',
                'secured' => '✓',
                'version' => '7.2',
            ],
            [
                'url' => 'http://site2.test',
                'secured' => '✕',
                'version' => '7.2',
            ],
        ], $response->all());
    }

    /**
     * @test
     */
    public function itWillGetIsolatedPhpVersion(): void
    {
        $site = 'site.test';
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/' . $site)
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/' . $site)
            ->andReturn("# ISOLATED_PHP_VERSION=7.2\nNginx content");

        $version = $this->siteIsolate->isolatedPhpVersion($site);
        $this->assertSame('7.2', $version);
    }

    /**
     * @test
     */
    public function itWillReturnNullWhenVersionNotFound(): void
    {
        $site = 'site.test';
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/' . $site)
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/' . $site)
            ->andReturn('Nginx content');

        $version = $this->siteIsolate->isolatedPhpVersion($site);
        $this->assertNull($version);
    }
}
