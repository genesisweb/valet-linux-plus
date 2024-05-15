<?php

namespace Valet\Tests;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use Silly\Application;
use Symfony\Component\Console\Tester\ApplicationTester;
use Valet\Facades\Configuration;
use Valet\Filesystem;

class TestCase extends PhpUnitTestCase
{
    protected Application $app;
    protected ApplicationTester $tester;

    public function setUp(): void
    {
        $this->prepTestConfig();
    }

    public function tearDown(): void
    {
        \Mockery::close();
    }

    /**
     * Prepare a test to run using the full application.
     */
    public function prepTestConfig(): void
    {
        require_once __DIR__.'/../cli/includes/helpers.php';
        Container::setInstance(new Container()); // Reset app container from previous tests
        $files = new Filesystem();
        if ($files->isDir(VALET_HOME_PATH)) {
            $files->remove(VALET_HOME_PATH);
        }

        Configuration::install();

        // Keep this file empty, as it's tailed in a test
        $files->touch(VALET_HOME_PATH.'/Log/nginx-error.log');

        require __DIR__.'/../cli/app.php';

        /** @var Application $app */
        $this->app = $app;
        $this->app->setAutoExit(false);
        $this->tester = new ApplicationTester($app);
    }
}
