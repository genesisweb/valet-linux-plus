<?php

namespace Valet\Facades;

/**
 * Class PhpFpm.
 *
 * @method static void         install(string $version = null, bool $installExt = true)
 * @method static void         uninstall()
 * @method static void         restart($version = null)
 * @method static void         stop($version = null)
 * @method static void         status($version = null)
 * @method static void         switchVersion(string $version, bool $updateCli = false, bool $ignoreExt = false)
 * @method static string       getCurrentVersion()
 * @method static false|string getPhpExecutablePath($version = null)
 * @method static string       socketFileName($version = null)
 * @method static string       normalizePhpVersion(string $version)
 * @method static string       validateVersion($version)
 * @method static string       fpmSocketFile($version)
 * @method static void         updateHomePath(string $oldHomePath, string $newHomePath)
 * @method static void         stopIfUnused(string $version)
 */
class PhpFpm extends Facade
{
}
