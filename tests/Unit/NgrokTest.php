<?php

namespace Valet\Tests\Unit;

use ConsoleComponents\Writer;
use Httpful\Response;
use Mockery;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Output\BufferedOutput;
use Valet\CommandLine;
use Valet\Filesystem;
use Valet\Ngrok;
use Valet\Request;
use Valet\Tests\TestCase;
use function Valet\swap;
use function Valet\user;

class NgrokTest extends TestCase
{
    private CommandLine|MockObject $commandLine;
    private Filesystem|MockObject $filesystem;
    private Ngrok $ngrok;

    public function setUp(): void
    {
        parent::setUp();

        $this->commandLine = Mockery::mock(CommandLine::class);
        $this->filesystem = Mockery::mock(Filesystem::class);

        $this->ngrok = new Ngrok(
            $this->commandLine,
            $this->filesystem
        );
    }

    /**
     * @test
     */
    public function itWillInstallSuccessfully(): void
    {
        Writer::fake();

        $request = Mockery::mock(Request::class);
        swap(Request::class, $request);

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(\sprintf('%s/bin/ngrok', VALET_ROOT_PATH))
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(\sprintf('%s/bin', VALET_ROOT_PATH), user());

        $request->shouldReceive('get')
            ->with('https://bin.equinox.io/c/bNyj1mQVY4c/ngrok-v3-stable-linux-amd64.tgz')
            ->once()
            ->andReturnSelf();

        $request->shouldReceive('send')
            ->withNoArgs()
            ->once()
            ->andReturn(new Response('ngrok-body-content', 'headers 0', $request));

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                \sprintf('%s/bin/ngrok-v3-stable-linux-amd64.tgz', VALET_ROOT_PATH),
                'ngrok-body-content'
            )
            ->once();

        \Valet\Facades\Filesystem::ensureDirExists(\sprintf('%s/bin', VALET_ROOT_PATH), user());
        $this->createFakeZip(\sprintf('%s/bin/ngrok-v3-stable-linux-amd64.tgz', VALET_ROOT_PATH));

        $this->filesystem
            ->shouldReceive('remove')
            ->with(
                \sprintf('%s/bin/ngrok-v3-stable-linux-amd64.tgz', VALET_ROOT_PATH)
            )
            ->once();

        $this->ngrok->install();

        unlink(\sprintf('%s/bin/ngrok-v3-stable-linux-amd64.tgz', VALET_ROOT_PATH));
        unlink(\sprintf('%s/bin/sample_file', VALET_ROOT_PATH));

        /** @var BufferedOutput $output */
        $output = Writer::output();
        $content = $output->fetch();
        $this->assertStringContainsString('Ngrok', $content);
        $this->assertStringContainsString('Installing', $content);
    }

    /**
     * @test
     */
    public function itWillNotOverrideWhenAlreadyInstalled(): void
    {
        Writer::fake();

        $request = Mockery::mock(Request::class);
        swap(Request::class, $request);

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(\sprintf('%s/bin/ngrok', VALET_ROOT_PATH))
            ->andReturnTrue();

        $this->filesystem
            ->shouldNotReceive('ensureDirExists');

        $request->shouldNotReceive('get')
            ->with('https://bin.equinox.io/c/bNyj1mQVY4c/ngrok-v3-stable-linux-amd64.tgz');

        $request->shouldNotReceive('send');

        $this->filesystem
            ->shouldNotReceive('putAsUser');

        $this->filesystem
            ->shouldNotReceive('remove');

        $this->ngrok->install();

        /** @var BufferedOutput $output */
        $output = Writer::output();
        $content = $output->fetch();
        $this->assertStringNotContainsString('Ngrok', $content);
        $this->assertStringNotContainsString('Installing', $content);
    }

    /**
     * @test
     */
    public function itWillFetchCurrentTunnelUrl(): void
    {
        $request = Mockery::mock(Request::class);
        swap(Request::class, $request);
        \Httpful\Httpful::register(\Httpful\Mime::JSON, new \Httpful\Handlers\JsonHandler(array('decode_as_array' => false)));

        $request->shouldReceive('get')
            ->once()
            ->with('http://127.0.0.1:4040/api/tunnels')
            ->andReturnSelf();

        $request->shouldReceive('send')
            ->once()
            ->withNoArgs()
            ->andReturn(new Response('{"tunnels":[{"name":"command_line","ID":"204020b4b272cf3ed13630aa5c18772b","uri":"/api/tunnels/command_line","public_url":"https://33e2-2405-201-2024-a899-720-a588-f13d-82fe.ngrok-free.app","proto":"http","config":{"addr":"http://info.localhost:80","inspect":true},"metrics":{"conns":{"count":0,"gauge":0,"rate1":0,"rate5":0,"rate15":0,"p50":0,"p90":0,"p95":0,"p99":0},"http":{"count":0,"rate1":0,"rate5":0,"rate15":0,"p50":0,"p90":0,"p95":0,"p99":0}}}],"uri":"/api/tunnels"}', "HTTP/1.1 200 OK\r\n
Content-Type: application/json\r\n
Date: Tue, 14 May 2024 12:13:28 GMT\r\n
Content-Length: 477
", $request));

        $output = $this->ngrok->currentTunnelUrl();

        $this->assertSame('https://33e2-2405-201-2024-a899-720-a588-f13d-82fe.ngrok-free.app', $output);
    }

    /**
     * @test
     */
    public function itWillSetNgrokAuthToken(): void
    {
        $this->commandLine
            ->shouldReceive('run')
            ->once()
            ->with(
                \sprintf('%s/bin/ngrok config add-authtoken test-token', VALET_ROOT_PATH)
            );

        $this->ngrok->setAuthToken('test-token');
    }

    private function createFakeZip(string $file): void
    {
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::CREATE);
        $zip->addFromString('sample_file', 'sample content');
        $zip->close();
    }
}
