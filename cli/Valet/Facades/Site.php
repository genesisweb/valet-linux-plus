<?php

namespace Valet\Facades;

use Tightenco\Collect\Support\Collection;

/**
 * Class Site.
 *
 * @method static string|null host(string $path)
 * @method static string      link(string $target, string $link)
 * @method static Collection  links()
 * @method static void        unlink(string $name)
 * @method static void        pruneLinks()
 * @method static void        resecureForNewDomain(string $oldDomain, string $domain)
 * @method static Collection  secured()
 * @method static Collection  proxies()
 * @method static void        proxyCreate(string $domain, string $host,bool $secure)
 * @method static void        proxyDelete(string $domain)
 * @method static void        secure(string $url, string $stub = null)
 * @method static void        unsecure(string $url, bool $preserveUnsecureConfig = false)
 * @method static void        regenerateSecuredSitesConfig()
 * @method static string      phpRcVersion($site)
 * @method static string      customPhpVersion($site)
 */
class Site extends Facade
{
}
