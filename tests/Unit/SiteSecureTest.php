<?php

namespace Unit;

use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\PhpFpm;
use Valet\SiteSecure;
use Valet\Tests\TestCase;

use function Valet\swap;
use function Valet\user;

class SiteSecureTest extends TestCase
{
    private Filesystem|MockObject $filesystem;
    private CommandLine|MockObject $commandLine;
    private Configuration|MockObject $config;
    private SiteSecure $siteSecure;

    public function setUp(): void
    {
        parent::setUp();

        $this->filesystem = Mockery::mock(Filesystem::class);
        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->config = Mockery::mock(Configuration::class);

        $this->siteSecure = new SiteSecure(
            $this->filesystem,
            $this->commandLine,
            $this->config
        );
    }

    /**
     * @test
     */
    public function itWillSecureNewDomain(): void
    {
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH . '/CA', user());

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates', user());

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('exists')
            ->twice()
            ->with(VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.key')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('remove')
            ->once()
            ->with('/usr/local/share/ca-certificates/ValetLinuxCASelfSigned.pem.crt')
            ->andReturnFalse();

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->with('sudo update-ca-certificates');

        $caExpireInDate = (new \DateTime())->diff(new \DateTime("+20 years"));
        $expiryInDays = (int)$caExpireInDate->format('%a'); // 20 years in days
        $subject = sprintf(
            '/C=/ST=/O=%s/localityName=/commonName=%s/organizationalUnitName=Developers/emailAddress=%s/',
            'Valet Linux CA Self Signed Organization',
            'Valet Linux CA Self Signed CN',
            'certificate@valet.linux',
        );
        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(sprintf(
                'openssl req -new -newkey rsa:2048 -days %s -nodes -x509 -subj "%s" -keyout "%s" -out "%s"',
                $expiryInDays,
                $subject,
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.key',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem'
            ));

        $this->filesystem
            ->shouldReceive('copy')
            ->once()
            ->with(
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem',
                '/usr/local/share/ca-certificates/ValetLinuxCASelfSigned.pem.crt'
            );

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->with('sudo update-ca-certificates');

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(sprintf(
                'certutil -d sql:$HOME/.pki/nssdb -A -t TC -n "%s" -i "%s"',
                'Valet Linux CA Self Signed Organization',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem'
            ));

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(sprintf(
                'certutil -d $HOME/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
                'Valet Linux CA Self Signed Organization',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem'
            ));

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(sprintf(
                'certutil -d $HOME/snap/firefox/common/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
                'Valet Linux CA Self Signed Organization',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem'
            ));

        $certificateExpireInDate = (new \DateTime())->diff(new \DateTime("+1 year"));
        $certificateExpireInDays = (int)$certificateExpireInDate->format('%a'); // 20 years in days

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/openssl.conf')
            ->andReturn('SSL conf');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.conf', 'SSL conf');

        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(\sprintf(
                'openssl genrsa -out %s 2048',
                VALET_HOME_PATH . '/Certificates/site.test.key'
            ));

        $subject = sprintf(
            '/C=/ST=/O=/localityName=/commonName=%s/organizationalUnitName=/emailAddress=%s/',
            'site.test',
            'certificate@valet.linux',
        );
        $this->commandLine
            ->shouldReceive('runAsUser')
            ->once()
            ->with(\sprintf(
                'openssl req -new -key %s -out %s -subj "%s" -config %s',
                VALET_HOME_PATH . '/Certificates/site.test.key',
                VALET_HOME_PATH . '/Certificates/site.test.csr',
                $subject,
                VALET_HOME_PATH . '/Certificates/site.test.conf'
            ));

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.srl')
            ->andReturnFalse();

        $caSrlParam = '-CAserial "' . VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.srl" -CAcreateserial';

        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->with(sprintf(
                'openssl x509 -req -sha256 -days %s -CA "%s" -CAkey "%s" %s -in %s -out %s -extensions v3_req -extfile %s',
                $certificateExpireInDays,
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.pem',
                VALET_HOME_PATH . '/CA/ValetLinuxCASelfSigned.key',
                $caSrlParam,
                VALET_HOME_PATH . '/Certificates/site.test.csr',
                VALET_HOME_PATH . '/Certificates/site.test.crt',
                VALET_HOME_PATH . '/Certificates/site.test.conf'
            ));

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_ROOT_PATH . '/cli/stubs/secure.valet.conf')
            ->andReturn('Nginx Conf');

        $this->config
            ->shouldReceive('get')
            ->once()
            ->with('port', 80)
            ->andReturn(80);

        $this->config
            ->shouldReceive('get')
            ->twice()
            ->with('https_port', 443)
            ->andReturn(443);

        $phpFpm->shouldReceive('getCurrentVersion')
            ->once()
            ->withNoArgs()
            ->andReturn('8.3');

        $phpFpm->shouldReceive('fpmSocketFile')
            ->once()
            ->with('8.3')
            ->andReturn(VALET_HOME_PATH . '/' . 'valet83.sock');

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test', 'Nginx Conf');

        $this->siteSecure->secure('site.test');
    }

    /**
     * @test
     */
    public function itWillUnsecureSite(): void
    {
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.crt')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Nginx/site.test');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.conf');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.key');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.csr');

        $this->filesystem
            ->shouldReceive('unlink')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates/site.test.crt');

        $this->siteSecure->unsecure('site.test');
    }

    /**
     * @test
     */
    public function itWillListSecuredSites(): void
    {
        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates')
            ->andReturn([
                'site.test.crt',
                'site.test.csr',
                'site.test.key',
                'site.test.conf',
                'site2.test.crt',
                'site2.test.csr',
                'site2.test.key',
                'site2.test.conf',
            ]);

        $sites = $this->siteSecure->secured();

        $this->assertSame(['site.test', 'site2.test'], $sites->toArray());
    }

    /**
     * @test
     */
    public function itWillRegenerateSecuredSites(): void
    {
        $phpFpm = Mockery::mock(PhpFpm::class);
        swap(PhpFpm::class, $phpFpm);

        $this->filesystem
            ->shouldReceive('scandir')
            ->once()
            ->with(VALET_HOME_PATH . '/Certificates')
            ->andReturn([
                'site.test.crt',
                'site.test.csr',
                'site.test.key',
                'site.test.conf',
                'site2.test.crt',
                'site2.test.csr',
                'site2.test.key',
                'site2.test.conf',
            ]);
        foreach (['site.test', 'site2.test'] as $site) {

            $this->filesystem
                ->shouldReceive('get')
                ->with(VALET_ROOT_PATH . '/cli/stubs/secure.valet.conf')
                ->once()
                ->andReturn('Nginx Conf');

            $this->config
                ->shouldReceive('get')
                ->once()
                ->with('port', 80)
                ->andReturn(80);

            $this->config
                ->shouldReceive('get')
                ->twice()
                ->with('https_port', 443)
                ->andReturn(443);

            $phpFpm->shouldReceive('getCurrentVersion')
                ->once()
                ->withNoArgs()
                ->andReturn('8.3');

            $phpFpm->shouldReceive('fpmSocketFile')
                ->once()
                ->with('8.3')
                ->andReturn(VALET_HOME_PATH . '/' . 'valet83.sock');

            $this->filesystem
                ->shouldReceive('putAsUser')
                ->once()
                ->with(VALET_HOME_PATH . '/Nginx/' . $site, 'Nginx Conf');
        }

        $this->siteSecure->regenerateSecuredSitesConfig();

        $this->assertTrue(true);
    }
}
