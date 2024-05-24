<?php

namespace Valet\Tests\Unit;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\PhpFpm;
use Valet\Site;
use Valet\Tests\TestCase;

use function Valet\swap;
use function Valet\user;

class SiteTest extends TestCase
{
    private Configuration|MockObject $config;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Site $site;

    public function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Configuration::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);

        $this->site = new Site(
            $this->config,
            $this->commandLine,
            $this->filesystem
        );
    }

    /**
     * @test
     */
    public function itWillGetHostForSelectedPath(): void
    {
        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturn([]);

        $host = $this->site->host('/test/home/path');

        $this->assertEquals('path', $host);
    }

    /**
     * @test
     */
    public function itWillGetHostFromSitesForSelectedPath(): void
    {
        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturn([
                'path',
                'path2',
            ]);

        $this->filesystem
            ->shouldReceive('realpath')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path')
            ->andReturn('/test/home/path');

        $host = $this->site->host('/test/home/path');

        $this->assertEquals('path', $host);
    }

    /**
     * @test
     */
    public function itWillLinkSiteSuccessfully(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites', user());

        $this->config
            ->shouldReceive('addPath')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites', true);

        $this->filesystem
            ->shouldReceive('symlinkAsUser')
            ->once()
            ->with('/test/home/path', VALET_HOME_PATH . '/Sites/path');

        $host = $this->site->link('/test/home/path', 'path');

        $this->assertEquals(VALET_HOME_PATH . '/Sites/path', $host);
    }

    /**
     * @test
     */
    public function itWillUnlinkSite(): void
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path');

        $this->site->unlink('path');

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function itWillNotUnlinkWhenSiteNotLinked(): void
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path')
            ->andReturnFalse();

        $this->filesystem
            ->shouldNotReceive('unlink');

        $this->site->unlink('path');

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function itWillLoadLinks(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates', user());

        $this->filesystem
            ->shouldReceive('scanDir')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates')
            ->andReturn(['path.lcl.crt']);

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('domain')
            ->andReturn('test');

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('port', 80)
            ->andReturn(80);

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('https_port', 443)
            ->andReturn(443);

        $this->filesystem
            ->shouldReceive('scanDir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturn(['path']);

        $this->filesystem
            ->shouldReceive('readLink')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites/path')
            ->andReturn('/test/home/path');

        $phpFpm = Mockery::mock(PhpFpm::class);
        $phpFpm->shouldReceive('getCurrentVersion')
            ->withNoArgs()
            ->once()
            ->andReturn('8.2');
        $phpFpm->shouldReceive('normalizePhpVersion')
            ->with('8.2')
            ->once()
            ->andReturn('8.2');
        swap(PhpFpm::class, $phpFpm);

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/path.test')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/path.test')
            ->andReturn('Nginx Conf');

        $links = $this->site->links();

        $this->assertSame([
            'path' => [
                'site' => 'path',
                'secured' => '',
                'url' => 'http://path.test',
                'path' => '/test/home/path',
                'phpVersion' => '8.2',
            ],
        ], $links->toArray());
    }
}
