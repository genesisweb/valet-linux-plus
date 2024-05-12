<?php

namespace Valet\Tests\Unit;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Valet\Configuration;
use Valet\Filesystem;

class ConfigurationTest extends TestCase
{
    private Filesystem|MockObject $filesystem;
    private Configuration $configuration;

    public function setUp(): void
    {
        parent::setUp();
        $_SERVER['SUDO_USER'] = 'test_user';

        $this->filesystem = \Mockery::mock(Filesystem::class);
        $this->configuration = new Configuration($this->filesystem);
    }

    /**
     * @test
    */
    public function itWillInstallConfigurationSuccessfully(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH, 'test_user');
        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH.'/Drivers')
            ->andReturn(false);
        $this->filesystem
            ->shouldReceive('mkdirAsUser')
            ->once()
            ->with(VALET_HOME_PATH.'/Drivers')
            ->andReturn(false);
        $this->filesystem
            ->shouldReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/SampleValetDriver.php')
            ->once()
            ->andReturn('Sample Valet Driver Content');
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(VALET_HOME_PATH.'/Drivers/SampleValetDriver.php', 'Sample Valet Driver Content');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Sites', 'test_user');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Extensions', 'test_user');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Log', 'test_user');
        $this->filesystem
            ->shouldReceive('touch')
            ->once()
            ->with(VALET_HOME_PATH.'/Log/nginx-error.log');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Certificates', 'test_user');
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
        ->andReturn(false);
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json', json_encode($this->defaultConfig(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
        $this->filesystem
            ->shouldReceive('chown')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json', 'test_user')
        ->andReturn(false);

        $this->configuration->install();
    }

    /**
     * @test
    */
    public function itWillNotOverrideWhenAlreadyInstalled(): void
    {
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH, 'test_user');
        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH.'/Drivers')
            ->andReturn(true);
        $this->filesystem
            ->shouldNotReceive('mkdirAsUser');
        $this->filesystem
            ->shouldNotReceive('get')
            ->with(VALET_ROOT_PATH.'/cli/stubs/SampleValetDriver.php');
        $this->filesystem
            ->shouldNotReceive('putAsUser')
            ->with(VALET_HOME_PATH.'/Drivers/SampleValetDriver.php', 'Sample Valet Driver Content');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Sites', 'test_user');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Extensions', 'test_user');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Log', 'test_user');
        $this->filesystem
            ->shouldReceive('touch')
            ->once()
            ->with(VALET_HOME_PATH.'/Log/nginx-error.log');
        $this->filesystem
            ->shouldReceive('ensureDirExists')
            ->once()
            ->with(VALET_HOME_PATH.'/Certificates', 'test_user');
        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
        ->andReturn(true);
        $this->filesystem
            ->shouldNotReceive('putAsUser')
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $this->defaultConfig(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );
        $this->filesystem
            ->shouldReceive('chown')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json', 'test_user')
        ->andReturn(false);

        $this->configuration->install();
    }

    /**
     * @test
     */
    public function itWillUninstallSuccessfully(): void
    {
        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH)
            ->andReturn(true);
        $this->filesystem
            ->shouldReceive('remove')
            ->once()
            ->with(VALET_HOME_PATH);

        $this->configuration->uninstall();
    }

    /**
     * @test
     */
    public function itWillNotUninstallWhenDirectoryNotExist(): void
    {
        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with(VALET_HOME_PATH)
            ->andReturn(false);
        $this->filesystem
            ->shouldNotReceive('remove')
            ->with(VALET_HOME_PATH);

        $this->configuration->uninstall();
    }

    /**
     * @test
     */
    public function itWillAddPathToConfig(): void
    {
        $defaultConfig = $this->defaultConfig();
        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode($defaultConfig));

        $defaultConfig['paths'] = ['new_path'];
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $defaultConfig,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );

        $this->configuration->addPath('new_path');
    }

    /**
     * @test
     */
    public function itWillRemovePathFromConfig(): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode([...$defaultConfig, 'paths' => ['new_path']]));

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $defaultConfig,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );

        $this->configuration->removePath('new_path');
    }

    /**
     * @test
     */
    public function itWillRemoveBrokenPathsFromConfig(): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('exists')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturnTrue();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode([...$defaultConfig, 'paths' => ['new_path']]));

        $this->filesystem
            ->shouldReceive('isDir')
            ->once()
            ->with('new_path')
            ->andReturnFalse();

        $this->filesystem
            ->shouldReceive('putAsUser')
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $defaultConfig,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );

        $this->configuration->prune();
    }

    public function configDataProvider(): array
    {
        return [
            [
                'domain',
                null,
                'test',
            ],
            [
                'paths',
                null,
                [],
            ],
            [
                'port',
                null,
                '80',
            ],
            [
                'mysql',
                [],
                [],
            ]
        ];
    }

    /**
     * @test
     * @dataProvider configDataProvider
     */
    public function itWillGetValueFromConfig(string $configKey, mixed $defaultValue, mixed $expectedValue): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode($defaultConfig));

        $value = $this->configuration->get($configKey, $defaultValue);

        $this->assertSame($expectedValue, $value);
    }

    public function updateConfigDataProvider(): array
    {
        return [
            [
                'domain',
                'new_domain',
            ],
            [
                'paths',
                ['test_path', 'new_path'],
            ],
            [
                'mysql',
                ['user' => 'mysql', 'password' => 'password'],
            ]
        ];
    }

    /**
     * @test
     * @dataProvider updateConfigDataProvider
     */
    public function itWillSetValueForConfig(string $configKey, mixed $updateValue): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode($defaultConfig));

        $updatedConfig = [...$defaultConfig, ...[$configKey => $updateValue]];
        $this->filesystem
            ->shouldReceive('putAsUser')
            ->once()
            ->with(
                VALET_HOME_PATH.'/config.json',
                json_encode(
                    $updatedConfig,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
                ).PHP_EOL
            );

        $value = $this->configuration->set($configKey, $updateValue);

        $this->assertSame($updatedConfig, $value);
    }

    public function domainDataProvider(): array
    {
        return [
            [
                'test',
                'test.test',
            ],
            [
                'site.test',
                'site.test',
            ],
            [
                'site.localhost',
                'site.localhost.test',
            ]
        ];
    }

    /**
     * @test
     * @dataProvider domainDataProvider
     */
    public function itWillParseDomain(string $siteName, string $expectedDomain): void
    {
        $defaultConfig = $this->defaultConfig();

        $this->filesystem
            ->shouldReceive('get')
            ->once()
            ->with(VALET_HOME_PATH.'/config.json')
            ->andReturn(json_encode($defaultConfig));


        $domain = $this->configuration->parseDomain($siteName);

        $this->assertSame($expectedDomain, $domain);
    }

    private function defaultConfig(): array
    {
        return [
            'domain' => 'test',
            'paths' => [],
            'port' => '80',
        ];
    }
}
