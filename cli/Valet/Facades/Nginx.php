<?php

namespace Valet\Facades;

use Illuminate\Support\Collection;

/**
 * Class Nginx.
 *
 * @method static void install()
 * @method static void restart()
 * @method static void stop()
 * @method static void status()
 * @method static void uninstall()
 * @method static void updatePort(string $newPort)
 * @method static Collection configuredSites()
 * @method static void installServer(string|float|null $phpVersion = null)
 */
class Nginx extends Facade
{
}
