<?php

namespace Valet\Facades;

use Illuminate\Support\Collection;

/**
 * Class Ngrok.
 *
 * @method static bool  isolateDirectory(string $directory, string $version, bool $secure = false)
 * @method static void  unIsolateDirectory(string $directory)
 * @method static Collection  isolatedDirectories()
 * @method static string  isolatedPhpVersion(string $url)
 */
class SiteIsolate extends Facade
{
}
