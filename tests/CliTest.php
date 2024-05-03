<?php

namespace Valet\Tests;

use Valet\Facades\Configuration;

class CliTest extends TestCase
{
    /**
     * @test
     */
    public function itWillReadDomainFromConfig(): void
    {
        $this->tester->run(['command' => 'domain']);

        $this->tester->assertCommandIsSuccessful();
        $domain = Configuration::read()['domain'];

        $this->assertEquals('test', $domain);
    }

}
