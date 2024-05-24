<?php

namespace Valet\Tests\Unit;

use Httpful\Handlers\JsonHandler;
use Httpful\Httpful;
use Httpful\Mime;
use Httpful\Response;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Valet\CommandLine;
use Valet\Configuration;
use Valet\Filesystem;
use Valet\Request;
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

        $this->assertTrue(true);
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

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function itWillUninstallSuccessfully(): void
    {
        $this->filesystem
            ->shouldReceive('unlink')
            ->with('/usr/local/bin/valet')
            ->once();

        $this->filesystem
            ->shouldReceive('unlink')
            ->with('/usr/local/bin/php')
            ->once();

        $this->valet->uninstall();

        $this->assertTrue(true);
    }

    /**
     * @test
     */
    public function itWillLoadExtensionsFromHomeDir(): void
    {
        $this->filesystem
            ->shouldReceive('isDir')
            ->with(VALET_HOME_PATH.'/Extensions')
            ->once()
            ->andReturn(true);

        $dummyExtensions = ['ext-1.php', 'ext-2.php'];
        $this->filesystem
            ->shouldReceive('scandir')
            ->with(VALET_HOME_PATH.'/Extensions')
            ->once()
            ->andReturn($dummyExtensions);

        foreach ($dummyExtensions as $dummyExtension) {
            $this->filesystem
                ->shouldReceive('isDir')
                ->with($dummyExtension)
                ->once()
                ->andReturnFalse();
        }

        $extensions = $this->valet->extensions();

        $this->assertSame([
            VALET_HOME_PATH.'/Extensions/ext-1.php',
            VALET_HOME_PATH.'/Extensions/ext-2.php',
        ], $extensions);
    }

    public function versionDataProvider(): array
    {
        return [
            [
                '2.0.0',
                true,
            ],
            [
                'v1.1.0',
                false,
            ],
        ];
    }

    /**
     * @test
     * @dataProvider versionDataProvider
     */
    public function itWillCompareLatestVersion(string $version, bool $expectedResponse): void
    {

        $request = Mockery::mock(Request::class);
        swap(Request::class, $request);
        Httpful::register(Mime::JSON, new JsonHandler(array('decode_as_array' => false)));

        $request->shouldReceive('get')
            ->with('https://api.github.com/repos/genesisweb/valet-linux-plus/releases/latest')
            ->once()
            ->andReturnSelf();

        $mockResponse = $this->getFile('github_response.json');
        $request->shouldReceive('send')
            ->once()
            ->withNoArgs()
            ->andReturn(new Response((string) $mockResponse, "HTTP/1.1 200 OK\r\n
Content-Type: application/json\r\n
Date: Tue, 14 May 2024 12:13:28 GMT\r\n
Content-Length: 477
", $request));

        $isLatest = $this->valet->onLatestVersion($version);

        $this->assertSame($expectedResponse, $isLatest);
    }

    /**
     * @test
     */
    public function itWillGetLatestVersion(): void
    {

        $request = Mockery::mock(Request::class);
        swap(Request::class, $request);
        Httpful::register(Mime::JSON, new JsonHandler(array('decode_as_array' => false)));

        $request->shouldReceive('get')
            ->with('https://api.github.com/repos/genesisweb/valet-linux-plus/releases/latest')
            ->once()
            ->andReturnSelf();

        $mockResponse = $this->getFile('github_response.json');
        $request->shouldReceive('send')
            ->once()
            ->withNoArgs()
            ->andReturn(new Response((string) $mockResponse, "HTTP/1.1 200 OK\r\n
Content-Type: application/json\r\n
Date: Tue, 14 May 2024 12:13:28 GMT\r\n
Content-Length: 477
", $request));

        $response = $this->valet->getLatestVersion();

        $this->assertSame('1.6.9', $response);
    }

    private function getFile(string $fileName): string|false
    {
        return file_get_contents(__DIR__ . '/MockResponse/' . $fileName);
    }
}
