<?php

namespace Valet;

use Illuminate\Support\Collection;
use Valet\Traits\Paths;

class SiteLink
{
    use Paths;

    private Filesystem $files;
    private Configuration $config;
    private SiteSecure $siteSecure;

    public function __construct(Filesystem $filesystem, Configuration $config, SiteSecure $siteSecure)
    {
        $this->files = $filesystem;
        $this->config = $config;
        $this->siteSecure = $siteSecure;
    }

    /**
     * Link the current working directory with the given name.
     */
    public function link(string $target, string $link): string
    {
        $linkPath = $this->sitesPath();
        $this->files->ensureDirExists($linkPath, user());

        $this->config->addPath($linkPath, true);

        $this->files->symlinkAsUser($target, $linkPath . '/' . $link);

        return $linkPath . '/' . $link;
    }

    /**
     * Unlink the given symbolic link.
     */
    public function unlink(string $name): void
    {
        $path = $this->sitesPath() . '/' . $name;
        if ($this->files->exists($path)) {
            $this->files->unlink($path);
        }
    }

    /**
     * Pretty print out all links in Valet.
     * @return Collection<int|string, array<string, int|string>>
     */
    public function links(): Collection
    {
        $certsPath = $this->certificatesPath();
        $path = $this->sitesPath();

        $this->files->ensureDirExists($certsPath, user());

        $securedSites = $this->siteSecure->secured();

        /** @var string $domain */
        $domain = $this->config->get('domain');

        $httpPort = $this->httpSuffix();
        $httpsPort = $this->httpsSuffix();

        return collect($this->files->scandir($path))->mapWithKeys(function ($site) use ($path) {
            return [$site => $this->files->readLink($path . '/' . $site)];
        })->map(function ($path, $site) use ($securedSites, $domain, $httpPort, $httpsPort) {
            $secured = $securedSites->contains($site . '.' . $domain);

            $url = \sprintf(
                '%s://%s.%s%s',
                $secured ? 'https' : 'http',
                $site,
                $domain,
                $secured ? $httpsPort : $httpPort
            );

            return [
                'url'        => $url,
                'secured'    => $secured ? '✓' : '✕',
                'path'       => $path,
            ];
        });
    }

    /**
     * Return http port suffix.
     */
    private function httpSuffix(): string
    {
        $port = $this->config->get('port', 80);

        return ($port == 80) ? '' : ':' . $port;
    }

    /**
     * Return https port suffix.
     */
    private function httpsSuffix(): string
    {
        $port = $this->config->get('https_port', 443);

        return ($port == 443) ? '' : ':' . $port;
    }
}
