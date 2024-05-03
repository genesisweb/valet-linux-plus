<?php

namespace Valet\Tests;

use ConsoleComponents\Writer;
use Mockery;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\DnsMasq;
use Valet\Configuration;
use Valet\Facades\Configuration as ConfigurationFacade;
use Valet\Nginx;
use Valet\PhpFpm;
use Valet\Site;
use function Valet\swap;

class CliTest extends TestCase
{
    /**
     * @test
     */
    public function itWillReadDomainFromConfig(): void
    {
        Writer::fake();
        $this->tester->run(['command' => 'domain']);

        $this->tester->assertCommandIsSuccessful();
        $domain = ConfigurationFacade::read()['domain'];

        $this->assertEquals('test', $domain);

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('Your current Valet domain is [test]', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillSetDomainInConfig(): void
    {
        Writer::fake();
        $dnsmasq = Mockery::mock(DnsMasq::class);
        $dnsmasq->shouldReceive('updateDomain')->once()->with('localhost');
        swap(DnsMasq::class, $dnsmasq);

        $config = Mockery::mock(Configuration::class);
        $config->shouldReceive('read')->andReturn(['domain' => 'test'])->once();
        $config->shouldReceive('updateKey')->with('domain', 'localhost')->once();
        swap(Configuration::class, $config);

        $site = Mockery::mock(Site::class);
        $site->shouldReceive('resecureForNewDomain')->with('test', 'localhost')->once();
        swap(Site::class, $site);

        $phpFpm = Mockery::mock(PhpFpm::class);
        $phpFpm->shouldReceive('restart')->withNoArgs()->once();
        swap(PhpFpm::class, $phpFpm);

        $nginx = Mockery::mock(Nginx::class);
        $nginx->shouldReceive('restart')->withNoArgs()->once();
        swap(Nginx::class, $nginx);

        $this->tester->run(['command' => 'domain', 'domain' => 'localhost']);

        $this->tester->assertCommandIsSuccessful();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('Your Valet domain has been updated to [localhost]', $output->fetch());

    }

}
