<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\DnsMasq;
use Valet\Filesystem;
use Valet\Tests\TestCase;

class DnsMasqTest extends TestCase
{
    private PackageManager|MockObject $packageManager;
    private ServiceManager|MockObject $serviceManager;
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private DnsMasq $dnsMasq;

    public function setUp(): void
    {
        parent::setUp();

        $this->packageManager = Mockery::mock(PackageManager::class);
        $this->serviceManager = Mockery::mock(ServiceManager::class);
        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->commandLine = Mockery::mock(CommandLine::class);

        $this->dnsMasq = new DnsMasq(
            $this->packageManager,
            $this->serviceManager,
            $this->filesystem,
            $this->commandLine
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
            ->with('dnsmasq');

        $this->serviceManager
            ->shouldReceive('enable')
            ->once()
            ->with('dnsmasq');

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/etc/NetworkManager/conf.d');

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/etc/dnsmasq.d');

        $this->filesystem
            ->shouldReceive('uncommentLine')
            ->once()
            ->with('IGNORE_RESOLVCONF', '/etc/default/dnsmasq');

        $this->filesystem
            ->shouldReceive('isLink')
            ->once()
            ->with('/etc/resolv.conf')
            ->andReturnFalse();

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (string $command): string {
                $this->assertSame('chattr -i /etc/resolv.conf', $command);
                return '';
            });

        $this->filesystem
            ->shouldReceive('remove')
            ->once()
            ->with('/opt/valet-linux');

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with('/opt/valet-linux');

        $this->serviceManager
            ->shouldReceive('removeValetDns')
            ->once();

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
        ->with('/etc/rc.local')
        ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('restore')
            ->once()
        ->with('/etc/rc.local');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
        ->with('/etc/dnsmasq.d/network-manager');

        $this->filesystem
            ->shouldReceive('backup')
            ->once()
        ->with('/etc/dnsmasq.conf');

        $this->filesystem
            ->shouldReceive('get')
            ->once()
        ->with(VALET_ROOT_PATH.'/cli/stubs/dnsmasq.conf')
        ->andReturn('dnsmasq.conf content');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
        ->with('/etc/dnsmasq.conf', 'dnsmasq.conf content');

        $this->filesystem
            ->shouldReceive('get')
            ->once()
        ->with(VALET_ROOT_PATH.'/cli/stubs/dnsmasq_options')
        ->andReturn('dnsmasq_options content');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
        ->with('/etc/dnsmasq.d/options', 'dnsmasq_options content');

        $this->filesystem
            ->shouldReceive('get')
            ->once()
        ->with(VALET_ROOT_PATH.'/cli/stubs/networkmanager.conf')
        ->andReturn('networkmanager.conf content');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
        ->with('/etc/NetworkManager/conf.d/valet.conf', 'networkmanager.conf content');

        $this->serviceManager
            ->shouldReceive('disabled')
            ->with('systemd-resolved')
            ->andReturnFalse();

        $this->serviceManager
            ->shouldReceive('disable')
            ->with('systemd-resolved');

        $this->serviceManager
            ->shouldReceive('stop')
            ->with('systemd-resolved');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                '/etc/dnsmasq.d/valet',
                'address=/.test/127.0.0.1'.PHP_EOL.'server=1.1.1.1'.PHP_EOL.'server=8.8.8.8'.PHP_EOL
            );

        $this->serviceManager
            ->shouldReceive('restart')
            ->with('dnsmasq');

        $this->dnsMasq->install('test');
    }

    /**
     * @test
     */
    public function itWillUninstallSuccessfully(): void
    {
        Writer::fake();

        $this->serviceManager
            ->shouldReceive('removeValetDns')
            ->once();

        $this->commandLine
            ->shouldReceive('passthru')
            ->with('rm -rf /opt/valet-linux');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with('/etc/dnsmasq.d/valet');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with('/etc/dnsmasq.d/options');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with('/etc/NetworkManager/conf.d/valet.conf');

        $this->filesystem
            ->shouldReceive('restore')
            ->once()
            ->with('/etc/systemd/resolved.conf');

        $this->filesystem
            ->shouldReceive('isLink')
            ->once()
            ->with('/etc/resolv.conf')
            ->andReturnFalse();

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->andReturnUsing(function (string $command): string {
                $this->assertSame('chattr -i /etc/resolv.conf', $command);
                return '';
            });

        $this->filesystem
            ->shouldReceive('restore')
            ->once()
            ->with('/etc/rc.local');

        $this->commandLine
            ->shouldReceive('passthru')
            ->once()
            ->with('rm -f /etc/resolv.conf');

        $this->serviceManager
            ->shouldReceive('stop')
            ->once()
            ->with('systemd-resolved');

        $this->serviceManager
            ->shouldReceive('start')
            ->once()
            ->with('systemd-resolved');

        $this->filesystem
            ->shouldReceive('symlink')
            ->once()
            ->with('/run/systemd/resolve/resolv.conf', '/etc/resolv.conf');

        $this->filesystem
            ->shouldReceive('restore')
            ->once()
            ->with('/etc/dnsmasq.conf');

        $this->filesystem
            ->shouldReceive('commentLine')
            ->once()
            ->with('IGNORE_RESOLVCONF', '/etc/default/dnsmasq');

        $this->packageManager
            ->shouldReceive('restartNetworkManager')
            ->once()
            ->withNoArgs();

        $this->serviceManager
            ->shouldReceive('restart')
            ->once()
            ->with('dnsmasq');

        $this->dnsMasq->uninstall();

        /** @var BufferedOutput $output */
        $output = Writer::output();

        $this->assertStringContainsString('Valet DNS changes have been rolled back', $output->fetch());
    }

    /**
     * @test
     */
    public function itWillStopServiceSuccessfully(): void
    {
        $this->serviceManager
            ->shouldReceive('stop')
            ->once()
            ->with('dnsmasq');

        $this->dnsMasq->stop();
    }

    /**
     * @test
     */
    public function itWillRestartServiceSuccessfully(): void
    {
        $this->serviceManager
            ->shouldReceive('restart')
            ->once()
            ->with('dnsmasq');

        $this->dnsMasq->restart();
    }

    /**
     * @test
     */
    public function itWillUpdateDomainSuccessfully(): void
    {
        $domain = 'local';
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                '/etc/dnsmasq.d/valet',
                'address=/.'.$domain.'/127.0.0.1'.PHP_EOL.'server=1.1.1.1'.PHP_EOL.'server=8.8.8.8'.PHP_EOL
            );

        $this->serviceManager
            ->shouldReceive('restart')
            ->once()
            ->with('dnsmasq');

        $this->dnsMasq->updateDomain($domain);
    }
}
