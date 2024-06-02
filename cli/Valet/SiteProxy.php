<?php

namespace Valet;

use Illuminate\Support\Collection;
use Valet\Traits\Paths;

class SiteProxy
{
    use Paths;

    private Filesystem $files;
    private Configuration $config;
    private SiteSecure $siteSecure;

    public function __construct(
        Filesystem $filesystem,
        Configuration $config,
        SiteSecure $siteSecure
    ) {
        $this->files = $filesystem;
        $this->config = $config;
        $this->siteSecure = $siteSecure;
    }

    /**
     * Build the Nginx proxy config for the specified domain.
     * @throws \InvalidArgumentException
     */
    public function proxyCreate(string $url, string $host, bool $secure = false): void
    {
        if (!preg_match('~^https?://.*$~', $host)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid URL', $host));
        }

        $domain = $this->config->get('domain');

        if (!str_ends_with($url, '.' . $domain)) {
            $url .= '.' . $domain;
        }

        $siteConf = $this->files->get(
            $secure
                ? VALET_ROOT_PATH . '/cli/stubs/secure.proxy.valet.conf'
                : VALET_ROOT_PATH . '/cli/stubs/proxy.valet.conf'
        );

        // Proxy specific variables
        $siteConf = strArrayReplace(
            [
                'VALET_PROXY_HOST' => $host,
            ],
            $siteConf
        );

        if ($secure) {
            $this->siteSecure->secure($url, $siteConf);
        } else {
            $siteConf = $this->siteSecure->buildUnsecureNginxServer($url, $siteConf);

            $this->files->putAsUser(
                $this->nginxPath($url),
                $siteConf
            );
        }
    }

    /**
     * Get all sites which are proxies (not Links, and contain proxy_pass directive).
     */
    public function proxies(): Collection
    {
        $nginxPath = $this->nginxPath();
        $domain = $this->config->get('domain');

        $securedSites = $this->siteSecure->secured();

        if (!$this->files->exists($nginxPath)) {
            return collect();
        }

        return collect($this->files->scandir($nginxPath))
            ->filter(function ($site) use ($domain) {
                // keep sites that match our TLD
                return str_ends_with($site, '.' . $domain);
            })->mapWithKeys(function ($site) use ($domain) {
                $host = $this->getProxyHostForSite($site) ?: '(other)';

                return [$site => $host];
            })->reject(function ($host) {
                // If proxy host is null, it may be just a normal SSL stub, or something else;
                // either way we exclude it from the list
                return $host === '(other)';
            })->map(function ($host, $site) use ($securedSites) {
                $secured = $securedSites->contains($site);
                $url = ($secured ? 'https' : 'http') . '://' . $site;

                return [
                    'url'     => $url,
                    'secured' => $secured ? '✓' : '✕',
                    'path'    => $host,
                ];
            });
    }

    /**
     * Identify whether a site is for a proxy by reading the host name from its config file.
     */
    private function getProxyHostForSite(string $siteName): ?string
    {
        if ($this->files->exists($this->nginxPath($siteName)) === false) {
            return null;
        }

        $siteConf = $this->files->get($this->nginxPath($siteName));
        preg_match('~proxy_pass\s+(?<host>https?://.*)\s*;~', $siteConf, $matches);

        if (!isset($matches['host'])) {
            return null;
        }

        return trim($matches['host']);
    }
}
