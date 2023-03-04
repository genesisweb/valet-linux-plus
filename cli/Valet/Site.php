<?php

namespace Valet;

use Tightenco\Collect\Support\Collection;

class Site
{
    public $config;
    public $cli;
    public $files;

    /**
     * Create a new Site instance.
     *
     * @param Configuration $config
     * @param CommandLine $cli
     * @param Filesystem $files
     */
    public function __construct(Configuration $config, CommandLine $cli, Filesystem $files)
    {
        $this->cli = $cli;
        $this->files = $files;
        $this->config = $config;
    }

    /**
     * Get the real hostname for the given path, checking links.
     *
     * @param string $path
     *
     * @return string|null
     */
    public function host($path)
    {
        foreach ($this->files->scandir($this->sitesPath()) as $link) {
            if (realpath($this->sitesPath() . '/' . $link) === $path) {
                return $link;
            }
        }

        return basename($path);
    }

    /**
     * Link the current working directory with the given name.
     *
     * @param string $target
     * @param string $link
     *
     * @return string
     */
    public function link($target, $link)
    {
        $this->files->ensureDirExists(
            $linkPath = $this->sitesPath(),
            user()
        );

        $this->config->prependPath($linkPath);

        $this->files->symlinkAsUser($target, $linkPath . '/' . $link);

        return $linkPath . '/' . $link;
    }

    /**
     * Pretty print out all links in Valet.
     *
     * @return Collection
     */
    public function links()
    {
        $certsPath = $this->certificatesPath();

        $this->files->ensureDirExists($certsPath, user());

        $certs = $this->getCertificates($certsPath);

        return $this->getLinks($this->sitesPath(), $certs);
    }

    /**
     * Get all sites which are proxies (not Links, and contain proxy_pass directive).
     *
     * @return \Tightenco\Collect\Support\Collection
     */
    public function proxies()
    {
        $dir = $this->nginxPath();
        $domain = $this->config->read()['domain'];
        $links = $this->links();
        $certs = $this->getCertificates($this->certificatesPath());
        if (!$this->files->exists($dir)) {
            return collect();
        }
        $proxies = collect($this->files->scandir($dir))
            ->filter(function ($site) use ($domain) {
                // keep sites that match our TLD

                return ends_with($site, '.' . $domain);
            })->map(function ($site) use ($domain) {
                // remove the TLD suffix for consistency
                return str_replace('.' . $domain, '', $site);
            })->reject(function ($site) use ($links) {
                return $links->has($site);
            })->mapWithKeys(function ($site) {
                $host = $this->getProxyHostForSite($site) ?: '(other)';

                return [$site => $host];
            })->reject(function ($host) {
                // If proxy host is null, it may be just a normal SSL stub, or something else; either way we exclude it from the list
                return $host === '(other)';
            })->map(function ($host, $site) use ($certs, $domain) {
                $secured = $certs->has($site);
                $url = ($secured ? 'https' : 'http') . '://' . $site . '.' . $domain;

                return [
                    'url' => $url,
                    'secured' => $secured ? ' X' : '',
                    'path' => $host,
                ];
            });

        return $proxies;
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param string $url
     *
     * @return void
     */
    public function proxyDelete($url)
    {
        $tld = $this->config->read()['domain'];
        if (!ends_with($url, '.' . $tld)) {
            $url .= '.' . $tld;
        }

        $this->unsecure($url);
        $this->files->unlink($this->nginxPath($url));

        info('Valet will no longer proxy [https://' . $url . '].');
    }

    /**
     * Identify whether a site is for a proxy by reading the host name from its config file.
     *
     * @param string $site Site name without TLD
     * @param string $configContents Config file contents
     *
     * @return string|null
     */
    public function getProxyHostForSite($site, $configContents = null)
    {
        $siteConf = $configContents ?: $this->getSiteConfigFileContents($site);

        if (empty($siteConf)) {
            return null;
        }

        $host = null;
        if (preg_match('~proxy_pass\s+(?<host>https?://.*)\s*;~', $siteConf, $patterns)) {
            $host = trim($patterns['host']);
        }

        return $host;
    }

    /**
     * Build the Nginx proxy config for the specified domain.
     *
     * @param string $url The domain name to serve
     * @param string $host The URL to proxy to, eg: http://127.0.0.1:8080
     * @param bool $secure
     *
     * @return string
     */
    public function proxyCreate($url, $host, $secure = false)
    {
        if (!preg_match('~^https?://.*$~', $host)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a valid URL', $host));
        }

        $domain = $this->config->read()['domain'];

        if (!ends_with($url, '.' . $domain)) {
            $url .= '.' . $domain;
        }

        $siteConf = $this->files->get(
            $secure ? __DIR__ . '/../stubs/secure.proxy.valet.conf' : __DIR__ . '/../stubs/proxy.valet.conf'
        );

        // General Variables
        $siteConf = str_array_replace(
            [
                'VALET_HOME_PATH' => $this->valetHomePath(),
                'VALET_SERVER_PATH' => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX' => VALET_STATIC_PREFIX,
                'VALET_SITE' => $url,
                'VALET_HTTP_PORT' => $this->config->get('port', 80),
                'VALET_HTTPS_PORT' => $this->config->get('https_port', 443),
            ],
            $siteConf
        );

        // Proxy specific variables
        $siteConf = str_array_replace(
            [
                'VALET_PROXY_HOST' => $host,
            ],
            $siteConf
        );

        if ($secure) {
            $this->secure($url, $siteConf);
        } else {
            $this->put($url, $siteConf);
        }

        $protocol = $secure ? 'https' : 'http';

        info('Valet will now proxy [' . $protocol . '://' . $url . '] traffic to [' . $host . '].');
    }

    public function valetLoopback()
    {
        return VALET_LOOPBACK;
    }

    /**
     * Get the path to Nginx site configuration files.
     */
    public function nginxPath($additionalPath = null)
    {
        return $this->valetHomePath() . '/Nginx' . ($additionalPath ? '/' . $additionalPath : '');
    }

    public function valetHomePath()
    {
        return VALET_HOME_PATH;
    }

    /**
     * Create the given nginx host.
     *
     * @param string $url
     * @param string $siteConf pregenerated Nginx config file contents
     *
     * @return void
     */
    public function put($url, $siteConf)
    {
        $this->unsecure($url);

        $this->files->ensureDirExists($this->nginxPath(), user());

        $siteConf = str_array_replace(
            [
                'VALET_HOME_PATH' => $this->valetHomePath(),
                'VALET_SERVER_PATH' => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX' => VALET_STATIC_PREFIX,
                'VALET_SITE' => $url,
                'VALET_HTTP_PORT' => $this->config->get('port', 80),
                'VALET_HTTPS_PORT' => $this->config->get('https_port', 443),
            ],
            $siteConf
        );

        $this->files->putAsUser(
            $this->nginxPath($url),
            $siteConf
        );
    }

    /**
     * Parse Nginx site config file contents to swap old loopback address to new.
     *
     * @param string $siteConf Nginx site config content
     * @param string $old Old loopback address
     * @param string $new New loopback address
     *
     * @return string
     */
    public function replaceOldLoopbackWithNew($siteConf, $old, $new)
    {
        $shouldComment = $new === VALET_LOOPBACK;

        $lookups = [];
        $lookups[] = '~#?listen .*:80; # valet loopback~';
        $lookups[] = '~#?listen .*:443 ssl http2; # valet loopback~';
        $lookups[] = '~#?listen .*:60; # valet loopback~';

        foreach ($lookups as $lookup) {
            preg_match($lookup, $siteConf, $matches);
            foreach ($matches as $match) {
                $replaced = str_replace($old, $new, $match);

                if ($shouldComment && strpos($replaced, '#') !== 0) {
                    $replaced = '#' . $replaced;
                }

                if (!$shouldComment) {
                    $replaced = ltrim($replaced, '#');
                }

                $siteConf = str_replace($match, $replaced, $siteConf);
            }
        }

        return $siteConf;
    }

    /**
     * @param $site
     * @param null $suffix
     *
     * @return string|null
     */
    public function getSiteConfigFileContents($site, $suffix = null)
    {
        $config = $this->config->read();
        $suffix = $suffix ?: '.' . $config['domain'];
        $file = str_replace($suffix, '', $site) . $suffix;

        return $this->files->exists($this->nginxPath($file)) ? $this->files->get($this->nginxPath($file)) : null;
    }

    /**
     * Get all certificates from config folder.
     *
     * @param string $path
     *
     * @return Collection
     */
    public function getCertificates($path = null)
    {
        $path = $path ?: $this->certificatesPath();

        return collect($this->files->scanDir($path))->filter(function ($value) {
            return ends_with($value, '.crt');
        })->map(function ($cert) {
            return substr($cert, 0, -9);
        })->flip();
    }

    /**
     * Get list of links and present them formatted.
     *
     * @param string $path
     * @param Collection $certs
     *
     * @return Collection
     */
    public function getLinks($path, $certs)
    {
        $config = $this->config->read();

        $httpPort = $this->httpSuffix();
        $httpsPort = $this->httpsSuffix();

        return collect($this->files->scanDir($path))->mapWithKeys(function ($site) use ($path) {
            return [$site => $this->files->readLink($path . '/' . $site)];
        })->map(function ($path, $site) use ($certs, $config, $httpPort, $httpsPort) {
            $secured = $certs->has($site);

            $url = ($secured ? 'https' : 'http') . '://' . $site . '.' . $config['domain'] . ($secured ? $httpsPort : $httpPort);

            return [$site, $secured ? ' X' : '', $url, $path];
        });
    }

    /**
     * Return http port suffix.
     *
     * @return string
     */
    public function httpSuffix()
    {
        $port = $this->config->get('port', 80);

        return ($port == 80) ? '' : ':' . $port;
    }

    /**
     * Return https port suffix.
     *
     * @return string
     */
    public function httpsSuffix()
    {
        $port = $this->config->get('https_port', 443);

        return ($port == 443) ? '' : ':' . $port;
    }

    /**
     * Unlink the given symbolic link.
     *
     * @param string $name
     *
     * @return void
     */
    public function unlink($name)
    {
        if ($this->files->exists($path = $this->sitesPath() . '/' . $name)) {
            $this->files->unlink($path);
        }
    }

    /**
     * Remove all broken symbolic links.
     *
     * @return void
     */
    public function pruneLinks()
    {
        $this->files->ensureDirExists($this->sitesPath(), user());

        $this->files->removeBrokenLinksAt($this->sitesPath());
    }

    /**
     * Resecure all currently secured sites with a fresh domain.
     *
     * @param string $oldDomain
     * @param string $domain
     *
     * @return void
     */
    public function resecureForNewDomain($oldDomain, $domain)
    {
        if (!$this->files->exists($this->certificatesPath())) {
            return;
        }

        $secured = $this->secured();

        foreach ($secured as $oldUrl) {
            $newUrl = str_replace('.' . $oldDomain, '.' . $domain, $oldUrl);
            $nginxConf = $this->getNginxConf($oldUrl);
            if ($nginxConf) {
                $nginxConf = str_replace($oldUrl, $newUrl, $nginxConf);
            }

            $this->unsecure($oldUrl);

            $this->secure(
                $newUrl,
                $nginxConf
            );
        }
    }

    /**
     * Get all the URLs that are currently secured.
     *
     * @return Collection
     */
    public function secured()
    {
        return collect($this->files->scandir($this->certificatesPath()))
            ->map(function ($file) {
                return str_replace(['.key', '.csr', '.crt', '.conf'], '', $file);
            })->unique()->values();
    }

    /**
     * Secure the given host with TLS.
     *
     * @param string $url
     * @param string $stub = null
     *
     * @return void
     */
    public function secure($url, $stub = null)
    {
        if (is_null($stub)) {
            $stub = $this->prepareConf($url, true);
        }
        $this->unsecure($url);

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $this->createCertificate($url);

        $this->createSecureNginxServer($url, $stub);
    }

    /**
     * Create and trust a certificate for the given URL.
     *
     * @param string $url
     *
     * @return void
     */
    public function createCertificate($url)
    {
        $keyPath = $this->certificatesPath() . '/' . $url . '.key';
        $csrPath = $this->certificatesPath() . '/' . $url . '.csr';
        $crtPath = $this->certificatesPath() . '/' . $url . '.crt';
        $confPath = $this->certificatesPath() . '/' . $url . '.conf';

        $this->buildCertificateConf($confPath, $url);
        $this->createPrivateKey($keyPath);
        $this->createSigningRequest($url, $keyPath, $csrPath, $confPath);

        $this->cli->runAsUser(sprintf(
            'openssl x509 -req -sha256 -days 365 -in %s -signkey %s -out %s -extensions v3_req -extfile %s',
            $csrPath,
            $keyPath,
            $crtPath,
            $confPath
        ));

        $this->trustCertificate($crtPath, $url);
    }

    /**
     * Create the private key for the TLS certificate.
     *
     * @param string $keyPath
     *
     * @return void
     */
    public function createPrivateKey($keyPath)
    {
        $this->cli->runAsUser(sprintf('openssl genrsa -out %s 2048', $keyPath));
    }

    /**
     * Create the signing request for the TLS certificate.
     *
     * @param string $url
     * @param string $keyPath
     * @param string $csrPath
     * @param string $confPath
     *
     * @return void
     */
    public function createSigningRequest($url, $keyPath, $csrPath, $confPath)
    {
        $this->cli->runAsUser(sprintf(
            'openssl req -new -key %s -out %s -subj "/C=US/ST=MN/O=Valet/localityName=Valet/commonName=%s/organizationalUnitName=Valet/emailAddress=valet/" -config %s -passin pass:',
            $keyPath,
            $csrPath,
            $url,
            $confPath
        ));
    }

    /**
     * Build the SSL config for the given URL.
     *
     * @param string $path
     * @param string $url
     *
     * @return void
     */
    public function buildCertificateConf($path, $url)
    {
        $config = str_replace('VALET_DOMAIN', $url, $this->files->get(__DIR__ . '/../stubs/openssl.conf'));
        $this->files->putAsUser($path, $config);
    }

    /**
     * Trust the given certificate file in the Mac Keychain.
     *
     * @param string $crtPath
     * @param string $url
     *
     * @return void
     */
    public function trustCertificate($crtPath, $url)
    {
        $this->cli->run(sprintf(
            'certutil -d sql:$HOME/.pki/nssdb -A -t TC -n "%s" -i "%s"',
            $url,
            $crtPath
        ));

        $this->cli->run(sprintf(
            'certutil -d $HOME/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
            $url,
            $crtPath
        ));
    }

    /**
     * @param string $url
     * @param string $stub = null
     *
     * @return void
     */
    public function createSecureNginxServer($url, $stub = null)
    {
        $this->files->putAsUser(
            $this->nginxPath($url),
            $this->buildSecureNginxServer($url, $stub)
        );
    }

    /**
     * Build the TLS secured Nginx server for the given URL.
     *
     * @param string $url
     * @param string $stub = null
     *
     * @return string
     */
    public function buildSecureNginxServer($url, $stub = null)
    {
        $stub = ($stub ?: $this->files->get(__DIR__ . '/../stubs/secure.valet.conf'));
        $path = $this->certificatesPath();

        return str_array_replace(
            [
                'VALET_HOME_PATH' => $this->valetHomePath(),
                'VALET_SERVER_PATH' => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX' => VALET_STATIC_PREFIX,
                'VALET_SITE' => $url,
                'VALET_CERT' => $path . '/' . $url . '.crt',
                'VALET_KEY' => $path . '/' . $url . '.key',
                'VALET_HTTP_PORT' => $this->config->get('port', 80),
                'VALET_HTTPS_PORT' => $this->config->get('https_port', 443),
                'VALET_REDIRECT_PORT' => $this->httpsSuffix(),
            ],
            $stub
        );
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     *
     * @param string $url
     *
     * @return void
     */
    public function unsecure($url)
    {
        if ($this->files->exists($this->certificatesPath() . '/' . $url . '.crt')) {
            $this->files->unlink($this->nginxPath() . '/' . $url);

            $this->files->unlink($this->certificatesPath() . '/' . $url . '.conf');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.key');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.csr');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.crt');

            $this->cli->run(sprintf('certutil -d sql:$HOME/.pki/nssdb -D -n "%s"', $url));
            $this->cli->run(sprintf('certutil -d $HOME/.mozilla/firefox/*.default -D -n "%s"', $url));
        }
    }

    /**
     * Regenerate all secured file configurations.
     *
     * @return void
     */
    public function regenerateSecuredSitesConfig()
    {
        $this->secured()->each(function ($url) {
            $this->createSecureNginxServer($url);
        });
    }

    /**
     * Get the path to the linked Valet sites.
     *
     * @return string
     */
    public function sitesPath()
    {
        return $this->valetHomePath() . '/Sites';
    }

    /**
     * Get the path to the Valet TLS certificates.
     *
     * @return string
     */
    public function certificatesPath()
    {
        return $this->valetHomePath() . '/Certificates';
    }

    /**
     * Get list of sites and return them formatted
     * Will work for symlink and normal site paths.
     *
     * @param $path
     * @param $certs
     * @return Collection
     */
    public function getSites($path, $certs)
    {
        $config = $this->config->read();

        $this->files->ensureDirExists($path, user());

        return collect($this->files->scandir($path))->mapWithKeys(function ($site) use ($path) {
            $sitePath = $path . '/' . $site;

            if ($this->files->isLink($sitePath)) {
                $realPath = $this->files->readLink($sitePath);
            } else {
                $realPath = $this->files->realpath($sitePath);
            }

            return [$site => $realPath];
        })->filter(function ($path) {
            return $this->files->isDir($path);
        })->map(function ($path, $site) use ($certs, $config) {
            $secured = $certs->has($site);
            $url = ($secured ? 'https' : 'http') . '://' . $site . '.' . $config['domain'];
            $phpVersion = $this->getPhpVersion($site . '.' . $config['domain']);

            return [
                'site' => $site,
                'secured' => $secured ? ' X' : '',
                'url' => $url,
                'path' => $path,
                'phpVersion' => $phpVersion,
            ];
        });
    }


    /**
     * Get the PHP version for the given site.
     *
     * @param string $url Site URL including the TLD
     * @return string
     */
    public function getPhpVersion($url)
    {
        $defaultPhpVersion = PHP_VERSION;
        $phpVersion = PhpFpm::normalizePhpVersion($this->customPhpVersion($url));
        if (empty($phpVersion)) {
            $phpVersion = PhpFpm::normalizePhpVersion($defaultPhpVersion);
        }

        return $phpVersion;
    }


    public function parked()
    {
        $certs = $this->getCertificates();

        $links = $this->getSites($this->sitesPath(), $certs);

        $config = $this->config->read();
        $parkedLinks = collect();
        foreach (array_reverse($config['paths']) as $path) {
            if ($path === $this->sitesPath()) {
                continue;
            }

            // Only merge on the parked sites that don't interfere with the linked sites
            $sites = $this->getSites($path, $certs)->filter(function ($site, $key) use ($links) {
                return !$links->has($key);
            });

            $parkedLinks = $parkedLinks->merge($sites);
        }

        return $parkedLinks;
    }

    /**
     * Get the site URL from a directory if it's a valid Valet site.
     *
     * @param string $directory
     * @return string
     */
    public function getSiteUrl($directory)
    {
        $tld = $this->config->read()['domain'];

        if ($directory == '.' || $directory == './') { // Allow user to use dot as current dir's site `--site=.`
            $directory = $this->host(getcwd());
        }

        $directory = str_replace('.' . $tld, '', $directory); // Remove .tld from sitename if it was provided

        if (!$this->parked()->merge($this->links())->where('site', $directory)->count() > 0) {
            throw new DomainException("The [{$directory}] site could not be found in Valet's site list.");
        }

        return $directory . '.' . $tld;
    }

    /**
     * Create new nginx config or modify existing nginx config to isolate this site
     * to a custom version of PHP.
     *
     * @param string $url
     * @param string $phpVersion
     * @param bool $secure
     * @return void
     */
    public function isolate($url, $phpVersion, $secure = false)
    {
        $stub = $secure ? __DIR__ . '/../stubs/secure.isolated.valet.conf' : __DIR__ . '/../stubs/isolated.valet.conf';

        // Isolate specific variables
        $siteConf = str_array_replace([
            'VALET_PHP_FPM_SOCKET' => PhpFpm::fpmSockName($phpVersion),
            'VALET_ISOLATED_PHP_VERSION' => $phpVersion,
        ], $this->files->get($stub));

        if ($secure) {
            $this->secure($url, $siteConf);
        } else {
            $this->put($url, $siteConf);
        }
    }


    /**
     * Remove PHP Version isolation from a specific site.
     *
     * @param string $valetSite
     * @return void
     */
    public function removeIsolation($valetSite)
    {
        // If a site has an SSL certificate, we need to keep its custom config file, but we can
        // just re-generate it without defining a custom `valet.sock` file
        // TODO: Validate site certificates instead of certificatePath.
        if ($this->files->exists($this->certificatesPath())) {
            $siteConf = $this->buildSecureNginxServer($valetSite);
            $this->files->putAsUser($this->nginxPath($valetSite), $siteConf);
        } else {
            // When site doesn't have SSL, we can remove the custom nginx config file to remove isolation
            $this->files->unlink($this->nginxPath($valetSite));
        }
    }

    /**
     * Extract PHP version of exising nginx conifg.
     *
     * @param string $url
     * @param string $siteConf
     * @param bool $returnDecimal
     * @return string|void
     */
    public function customPhpVersion($url, $siteConf = null, $returnDecimal = false)
    {
        if (!$this->files->exists($this->nginxPath($url))) {
            return;
        }

        $siteConf = $siteConf ?: $this->files->get($this->nginxPath($url));
        if (strpos($siteConf, '# ' . ISOLATED_PHP_VERSION) !== false) {
            preg_match('/^# ISOLATED_PHP_VERSION=(.*?)\n/m', $siteConf, $version);
            if ($returnDecimal) {
                return $version[1];
            }
            return preg_replace("/[^\d]*/", '', $version[1]); // Example output: "74" or "81"
        }
    }

    /**
     * Replace .sock file in an Nginx site configuration file contents.
     *
     * @param string $siteConf
     * @param string $phpVersion
     * @return string
     */
    public function replaceSockFile($siteConf, $phpVersion)
    {
        $sockFile = PhpFpm::fpmSockName($phpVersion);

        $siteConf = preg_replace('/valet[0-9]*.sock/', $sockFile, $siteConf);
        // Remove ISOLATED_PHP_VERSION line from config
        $siteConf = preg_replace('/# ' . ISOLATED_PHP_VERSION . '.*\n/', '', $siteConf);

        return '# ' . ISOLATED_PHP_VERSION . '=' . $phpVersion . PHP_EOL . $siteConf;
    }

    /**
     * Get PHP version from .valetphprc for a site.
     *
     * @param string $site
     * @return string|null
     */
    public function phpRcVersion($site)
    {
        if ($site = $this->parked()->merge($this->links())->where('site', $site)->first()) {
            $path = data_get($site, 'path') . '/.valetphprc';

            if ($this->files->exists($path)) {
                return PhpFpm::normalizePhpVersion(trim($this->files->get($path)));
            }
        }
    }

    private function getNginxConf($url)
    {
        if (!$this->files->exists($this->nginxPath() . '/' . $url)) {
            return null;
        }

        return $this->files->get($this->nginxPath() . '/' . $url);
    }

    /**
     * Prepare Nginx Conf based on existing config file.
     * @param string $url
     * @param bool $requireSecure
     * @return null|string
     **/
    private function prepareConf(string $url, bool $requireSecure = false) {
        /*
         * Find Stub name
         * Get related variables.
         * There are two stub types: isolated & proxy.
         * If Isolated then get PHP version
         * If proxy then get proxy_pass host.
         * */
        if (!$this->files->exists($this->nginxPath($url))) {
            return null;
        }

        $existingConf = $this->files->get($this->nginxPath($url));

        preg_match('/# valet stub: (?<tls>secure)?(?:\.)?(?<stub>.*?).valet.conf/m', $existingConf, $stubDetail);

        if (empty($stubDetail['stub'])) {
            return null;
        }

        if ($stubDetail['stub'] === 'proxy') {
            // Find proxy_pass from existingConf.

            // get new stub and replace proxy_pass and return.
        }

        if ($stubDetail['stub'] === 'isolated') {
            $phpVersion = $this->customPhpVersion($url, $existingConf, true);
            // empty($stubDetail['tls']) ||  We can use this statement if needed.
            $stub = $requireSecure ?
                __DIR__ . '/../stubs/secure.isolated.valet.conf' :
                __DIR__ . '/../stubs/isolated.valet.conf';
            $stub = $this->files->get($stub);
            // Isolate specific variables
            return str_array_replace([
                'VALET_PHP_FPM_SOCKET' => \PhpFpm::fpmSockName($phpVersion),
                'VALET_ISOLATED_PHP_VERSION' => $phpVersion,
            ], $stub);
        }
        return null;
    }
}
