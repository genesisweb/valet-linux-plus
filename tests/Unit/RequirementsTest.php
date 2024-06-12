<?php

namespace Valet\Tests\Unit;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Requirements;
use Valet\Tests\TestCase;

class RequirementsTest extends TestCase
{
    private CommandLine|MockObject $commandLine;
    private Requirements $requirements;

    public function setUp(): void
    {
        parent::setUp();

        $this->commandLine = Mockery::mock(CommandLine::class);

        $this->requirements = new Requirements(
            $this->commandLine
        );
    }

    /**
     * @test
     */
    public function itWillVerifyIfSELinuxIsEnabled(): void
    {
        $this->commandLine
            ->shouldReceive('run')
            ->with('sestatus')
            ->once()
            ->andReturn('@SELinux status: disabled');

        $this->requirements->setIgnoreSELinux(false);
        $this->requirements->check();
    }

    /**
     * @test
     */
    public function itWillThrowExceptionWhenSELinuxIsEnabledAndEnforcing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SELinux is in enforcing mode');

        $this->commandLine
            ->shouldReceive('run')
            ->with('sestatus')
            ->once()
            ->andReturn("SELinux status: enabled\nCurrent mode: enforcing");

        $this->requirements->setIgnoreSELinux(false);
        $this->requirements->check();
    }

    /**
     * @test
     */
    public function itWillSkipCheckingSELinuxWhenIgnored(): void
    {
        $this->commandLine
            ->shouldNotReceive('run')
            ->with('sestatus');

        $this->requirements->setIgnoreSELinux();
        $this->requirements->check();
    }
}
