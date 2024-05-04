<?php

namespace Valet\Facades;

use Illuminate\Support\Collection;

/**
 * Class PhpFpm.
 *
 * @method static void         install(string $version = null, bool $installExt = true)
 * @method static void         uninstall()
 * @method static void         restart($version = null)
 * @method static void         stop($version = null)
 * @method static void         status($version = null)
 * @method static void         switchVersion(string $version = null, bool $updateCli = null, bool $ignoreExt = null)
 * @method static string       getCurrentVersion()
 * @method static void         isolateDirectory($site, $phpVersion, $secure = false)
 * @method static Collection   isolatedDirectories()
 * @method static void         unIsolateDirectory($site)
 * @method static false|string getPhpExecutablePath($version = null)
 * @method static string       socketFileName($version = null)
 * @method static string       normalizePhpVersion($version)
 * @method static string       validateVersion($version)
 * @method static string       fpmSocketFile($version)
 * @method static void         updateHomePath(string $oldHomePath, string $newHomePath)
 */
class PhpFpm extends Facade
{
}
