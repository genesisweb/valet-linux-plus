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
    public function itWillPruneLinks(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->with(VALET_HOME_PATH . '/Sites', user())
            ->once();

        $this->filesystem
            ->shouldReceive('removeBrokenLinksAt')
            ->with(VALET_HOME_PATH . '/Sites')
            ->once();

        $this->site->pruneLinks();

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function itWillGetSiteUrl(): void
    {
        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('domain')
            ->andReturn('test');

        // servedSites
        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('paths', [])
            ->andReturn(['path1']);

        $dummySites = ['test', 'site2'];
        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with('path1')
            ->andReturn($dummySites);

        foreach ($dummySites as $dummySite) {
            $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with('path1/' . $dummySite)
            ->andReturnTrue();
        }

        $nginxSites = ['proxy1', 'proxy2'];
        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturn($nginxSites);

        foreach ($nginxSites as $nginxSite) {
            $this->filesystem
                ->shouldReceive('realpath')
                ->once()
                ->with(VALET_HOME_PATH . '/Sites/' . $nginxSite)
                ->andReturn('/real/path/' . $nginxSite);
        }

        $output = $this->site->getSiteUrl('test');
        $this->assertSame('test.test', $output);
    }

    /**
     * @test
     */
    public function itWillGetPhpRcVersion(): void
    {
        // servedSites
        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('paths', [])
            ->andReturn(['path1']);

        $dummySites = ['test', 'site2'];
        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with('path1')
            ->andReturn($dummySites);

        foreach ($dummySites as $dummySite) {
            $this->filesystem
                ->shouldReceive('isDir')
                ->once()
                ->with('path1/' . $dummySite)
                ->andReturnTrue();
        }

        $nginxSites = ['proxy1', 'proxy2'];
        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Sites')
            ->andReturn($nginxSites);

        foreach ($nginxSites as $nginxSite) {
            $this->filesystem
                ->shouldReceive('realpath')
                ->once()
                ->with(VALET_HOME_PATH . '/Sites/' . $nginxSite)
                ->andReturn('/real/path/' . $nginxSite);
        }

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with('path1/test/.valetphprc')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with('path1/test/.valetphprc')
            ->andReturn(' 8.2 ');

        $phpFpm = Mockery::mock(PhpFpm::class);
        $phpFpm->shouldReceive('normalizePhpVersion')
            ->once()
            ->with('8.2')
            ->andReturn('8.2');
        swap(PhpFpm::class, $phpFpm);

        $version = $this->site->phpRcVersion('test');
        $this->assertSame('8.2', $version);
    }
}
