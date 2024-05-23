<?php

namespace Valet\Tests\Unit;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Tests\TestCase;
use Valet\Valet;
use function Valet\swap;

class ValetTest extends TestCase
{
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Valet $valet;

    public function setUp(): void
    {
        parent::setUp();

        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);

        $this->valet = new Valet(
            $this->commandLine,
            $this->filesystem
        );
    }

    /**
     * @test
     */
    public function itWillLinkValetBinFileToSystemPath(): void
    {
        $this->commandLine
            ->shouldReceive('run')
            ->with('ln -snf '.realpath(VALET_ROOT_PATH.'/valet').' /usr/local/bin/valet')
            ->once();

        $this->valet->symlinkToUsersBin();
    }

    /**
     * @test
     */
    public function itWillLinkPhpBinFileToSystemPath(): void
    {
        $config = Mockery::mock(Configuration::class);
        swap(Configuration::class, $config);
        $execPath = $_SERVER['_'] ?? '/usr/bin/php';

        $this->filesystem
            ->shouldReceive('realpath')
            ->with($execPath)
            ->andReturn('/usr/bin/php');

        $config->shouldReceive('set')
            ->with('fallback_binary', '/usr/bin/php')
            ->once();

        $this->commandLine
            ->shouldReceive('run')
            ->with('ln -snf '.realpath(VALET_ROOT_PATH.'/php').' /usr/local/bin/php')
            ->once();

        $this->valet->symlinkPhpToUsersBin();
    }
}
