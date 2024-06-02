<?php

namespace Valet\Facades;

use Illuminate\Support\Collection;

/**
 * Class Ngrok.
 *
 * @method static void          proxyCreate(string $url, string $host, bool $secure = false)
 * @method static void          proxyDelete(string $url) @deprecated
 * @method static Collection    proxies()
 */
class SiteProxy extends Facade
{
}
