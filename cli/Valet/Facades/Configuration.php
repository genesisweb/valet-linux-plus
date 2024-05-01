<?php

namespace Valet\Facades;

/**
 * Class Configuration.
 *
 * @method static void   install()
 * @method static void   uninstall()
 * @method static void   createConfigurationDirectory()
 * @method static void   createDriversDirectory()
 * @method static void   createSitesDirectory()
 * @method static void   createExtensionsDirectory()
 * @method static void   createLogDirectory()
 * @method static void   createCertificatesDirectory()
 * @method static void   writeBaseConfiguration()
 * @method static void   addPath(string $path, bool $prepend = false)
 * @method static void   prependPath(string $path)
 * @method static void   removePath(string $path)
 * @method static void   prune()
 * @method static array  read()
 * @method static mixed  get(string $key, mixed $default = null)
 * @method static mixed  set(string $key, mixed $default = null)
 * @method static array  updateKey(string $key, mixed $value)
 * @method static void   write(array $config)
 * @method static string path()
 * @method static string parseDomain(string $site)
 */
class Configuration extends Facade
{
}
