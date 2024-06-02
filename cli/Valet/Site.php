<?php

namespace Valet;

use Illuminate\Support\Collection;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Traits\Paths;

class Site
{
    use Paths;

    public Configuration $config;
    public CommandLine $cli;
    public Filesystem $files;

    /**
     * Create a new Site instance.
     */
    public function __construct(Configuration $config, CommandLine $cli, Filesystem $files)
    {
        $this->config = $config;
        $this->cli = $cli;
        $this->files = $files;
    }

    /**
     * Remove all broken symbolic links.
     */
    public function pruneLinks(): void
    {
        $this->files->ensureDirExists($this->sitesPath(), user());

        $this->files->removeBrokenLinksAt($this->sitesPath());
    }

    /**
     * Get the site URL from a directory if it's a valid Valet site.
     * @throws \DomainException
     */
    public function getSiteUrl(string $directory): string
    {
        $tld = $this->config->get('domain');

        if ($directory == '.' || $directory == './') { // Allow user to use dot as current directory site `--site=.`
            $directory = basename((string)getcwd());
        }

        $directory = str_replace('.' . $tld, '', $directory); // Remove .tld from site name if it was provided

        $servedSites = $this->servedSites();
        if (!$servedSites->has($directory)) {
            throw new \DomainException("The [{$directory}] site could not be found in Valet's site list.");
        }

        return $directory . '.' . $tld;
    }

    /**
     * Get PHP version from .valetphprc for a site.
     */
    public function phpRcVersion(string $site): ?string
    {
        $servedSites = $this->servedSites();
        if ($servedSites->has($site)) {
            $sitePath = $servedSites->get($site);
            $path = $sitePath . '/.valetphprc';

            if ($this->files->exists($path)) {
                return PhpFpmFacade::normalizePhpVersion(trim($this->files->get($path)));
            }
        }
        return null;
    }

    /**
     * List of all sites served by valet
     * @return Collection<int|string, string>
     */
    private function servedSites(): Collection
    {
        $parkedSites = [];

        /** @var array<int, string> $parkedPaths */
        $parkedPaths = $this->config->get('paths', []);
        foreach ($parkedPaths as $path) {
            if ($path === $this->sitesPath()) {
                continue;
            }

            $sites = $this->files->scandir($path);
            foreach ($sites as $site) {
                if ($this->files->isDir($path . '/' . $site)) {
                    $parkedSites[$site] = $path . '/' . $site;
                }
            }
        }

        // Get sites from links
        $linkedSites = $this->files->scandir($this->sitesPath());
        foreach ($linkedSites as $linkedSite) {
            $parkedSites[$linkedSite] = $this->files->realpath($this->sitesPath($linkedSite));
        }

        return collect($parkedSites);
    }
}
