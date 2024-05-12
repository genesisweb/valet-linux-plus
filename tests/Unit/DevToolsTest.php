<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\DevTools;
use Valet\Filesystem;

class DevToolsTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private DevTools $devTools;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);

        $this->devTools = new DevTools(
            $this->packageManager,
            $this->serviceManager,
            $this->commandLine,
            $this->filesystem
        );
    }

    /**
     * @test
     */
    public function itWillGetBinForGivenServiceUsingWhichCommand(): void
    {
        $service = 'service_name';

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('which ' . $service, $path);
                return '/path/to/bin/service_name';
            });

        $binPath = $this->devTools->getBin($service);

        $this->assertSame('/path/to/bin/service_name', $binPath);
    }

    /**
     * @test
     */
    public function itWillGetBinForGivenServiceUsingLocateCommand(): void
    {
        $service = 'service_name';

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('which ' . $service, $path);
                $callback(1, '');
            });

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('locate --regex bin/' . $service. '$', $path);
                return "/path/to/bin/service_name\n/second-path/bin/service_name\n/third-path/bin/service_name\n";
            });

        $binPath = $this->devTools->getBin($service);

        $this->assertSame('/path/to/bin/service_name', $binPath);
    }

    /**
     * @test
     */
    public function itWillGetBinForGivenServiceAndExcludeGivenServiceByWhichCommand(): void
    {
        $service = 'service_name';
        $excludedServices = ['/path/to/bin/service_name'];

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('which ' . $service, $path);
                return '/path/to/bin/service_name';
            });

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('locate --regex bin/' . $service. '$', $path);
                return "/path/to/bin/service_name\n/second-path/bin/service_name\n/third-path/bin/service_name\n";
            });

        $binPath = $this->devTools->getBin($service, $excludedServices);

        $this->assertSame('/second-path/bin/service_name', $binPath);
    }

    /**
     * @test
     */
    public function itWillGetBinForGivenServiceAndExcludeGivenServiceByLocateCommand(): void
    {
        $service = 'service_name';
        $excludedServices = ['/path/to/bin/service_name'];

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('which ' . $service, $path);
                $callback(1, '');
            });

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('locate --regex bin/' . $service. '$', $path);
                return "/path/to/bin/service_name\n/second-path/bin/service_name\n/third-path/bin/service_name\n";
            });

        $binPath = $this->devTools->getBin($service, $excludedServices);

        $this->assertSame('/second-path/bin/service_name', $binPath);
    }

    /**
     * @test
     */
    public function itWillRunServiceWhenAvailable(): void
    {
        $service = 'service_name';

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('which ' . $service, $path);
                return '/path/to/bin/service_name';
            });

        $this->commandLine
            ->shouldReceive('quietly')
            ->once()
            ->with('/path/to/bin/service_name /path/to/folder');

        $this->devTools->run('/path/to/folder', $service);
    }

    /**
     * @test
     */
    public function itWillShowOutputWhenServiceNotAvailableToRun(): void
    {
        Writer::fake();
        $service = 'service_name';

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('which ' . $service, $path);
                $callback(1, '');
            });

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function ($path, $callback) use ($service) {
                $this->assertSame('locate --regex bin/' . $service. '$', $path);
                return "";
            });

        $this->commandLine
            ->shouldNotReceive('quietly');

        $this->devTools->run('/path/to/folder', $service);

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('service_name not available', $output->fetch());
    }
}
