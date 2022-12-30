<?php

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\PhpFpm;

class PhpFpmTest extends TestCase
{
    protected function setUp(): void
    {
        $_SERVER['SUDO_USER'] = user();

        Container::setInstance(new Container());
    }


    protected function tearDown(): void
    {
        exec('rm -rf ' . __DIR__ . '/output');
        mkdir(__DIR__ . '/output');
        touch(__DIR__ . '/output/.gitkeep');

        Mockery::close();
    }


    public function test_install_configuration_replaces_user_and_sock_in_config_file()
    {
        swap(PackageManager::class, Mockery::mock(PackageManager::class));
        swap(ServiceManager::class, Mockery::mock(ServiceManager::class));

        copy(__DIR__ . '/files/fpm.conf', __DIR__ . '/output/valet.conf');

        resolve(StubForUpdatingFpmConfigFiles::class)->installConfiguration();
        $contents = file_get_contents(__DIR__ . '/output/valet.conf');
        $this->assertStringContainsString(sprintf("\nuser = %s", user()), $contents);
        $this->assertStringContainsString(sprintf("\ngroup = %s", group()), $contents);
        $this->assertStringContainsString(sprintf("\nlisten.owner = %s", user()), $contents);
        $this->assertStringContainsString(sprintf("\nlisten.group = %s", group()), $contents);
        $this->assertStringContainsString("\nlisten = " . VALET_HOME_PATH . "/valet", $contents);
    }
}


class StubForUpdatingFpmConfigFiles extends PhpFpm
{
    public function fpmConfigPath($phpVersion = null)
    {
        return __DIR__ . '/output';
    }

    public function getVersion($real = false)
    {
        return '7.1';
    }

    public function systemdDropInOverride()
    {
        return;
    }
}
