<?php

namespace Valet;

use Illuminate\Support\Collection;
use Valet\Facades\PhpFpm as PhpFpmFacade;

class Site
{
    public Configuration $config;
    public CommandLine $cli;
    public Filesystem $files;

    private string $caCertificatePath = '/usr/local/share/ca-certificates/';
    private string $caCertificatePem = 'ValetLinuxCASelfSigned.pem';
    private string $caCertificateKey = 'ValetLinuxCASelfSigned.key';
    private string $caCertificateSrl = 'ValetLinuxCASelfSigned.srl';
    private string $caCertificateOrganization = 'Valet Linux CA Self Signed Organization';
    private string $caCertificateCommonName = 'Valet Linux CA Self Signed CN';
    private string $certificateDummyEmail = 'certificate@valet.linux';

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
     * Get the real hostname for the given path, checking links.
     */
    public function host(string $path): string
    {
        foreach ($this->files->scandir($this->sitesPath()) as $link) {
            if ($this->files->realpath($this->sitesPath() . '/' . $link) === $path) {
                return $link;
            }
        }

        return basename($path);
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
     */
    public function links(): Collection
    {
        $certsPath = $this->certificatesPath();

        $this->files->ensureDirExists($certsPath, user());

        $certs = $this->getCertificates($certsPath);

        return $this->getLinks($this->sitesPath(), $certs);
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
            $secure ? __DIR__ . '/../stubs/secure.proxy.valet.conf' : __DIR__ . '/../stubs/proxy.valet.conf'
        );

        // General Variables
        $siteConf = strArrayReplace(
            [
                'VALET_HOME_PATH'     => VALET_HOME_PATH,
                'VALET_SERVER_PATH'   => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX' => VALET_STATIC_PREFIX,
                'VALET_SITE'          => $url,
                'VALET_HTTP_PORT'     => $this->config->get('port', 80),
                'VALET_HTTPS_PORT'    => $this->config->get('https_port', 443),
            ],
            $siteConf
        );

        // Proxy specific variables
        $siteConf = strArrayReplace(
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
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     */
    public function proxyDelete(string $url): void
    {
        $this->unsecure($url);
        $this->files->unlink($this->nginxPath($url));
    }

    /**
     * Get all sites which are proxies (not Links, and contain proxy_pass directive).
     */
    public function proxies(): Collection
    {
        $dir = $this->nginxPath();
        $domain = $this->config->get('domain');
        $links = $this->links();
        $certs = $this->getCertificates($this->certificatesPath());
        if (!$this->files->exists($dir)) {
            return collect();
        }

        return collect($this->files->scandir($dir))
            ->filter(function ($site) use ($domain) {
                // keep sites that match our TLD

                return str_ends_with($site, '.' . $domain);
            })->map(function ($site) use ($domain) {
                // remove the TLD suffix for consistency
                return str_replace('.' . $domain, '', $site);
            })->reject(function ($site) use ($links) {
                return $links->has($site);
            })->mapWithKeys(function ($site) {
                $host = $this->getProxyHostForSite($site) ?: '(other)';

                return [$site => $host];
            })->reject(function ($host) {
                // If proxy host is null, it may be just a normal SSL stub, or something else;
                // either way we exclude it from the list
                return $host === '(other)';
            })->map(function ($host, $site) use ($certs, $domain) {
                $secured = $certs->has($site);
                $url = ($secured ? 'https' : 'http') . '://' . $site . '.' . $domain;

                return [
                    'url'     => $url,
                    'secured' => $secured ? '✓' : '✕',
                    'path'    => $host,
                ];
            });
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
     * Re-secure all currently secured sites with a fresh domain.
     */
    public function resecureForNewDomain(string $oldDomain, string $domain): void
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
     */
    public function secured(): Collection
    {
        return collect($this->files->scandir($this->certificatesPath()))
            ->map(function ($file) {
                return str_replace(['.key', '.csr', '.crt', '.conf'], '', $file);
            })->unique()->values();
    }

    /**
     * Secure the given host with TLS.
     */
    public function secure(string $url, string $stub = null): void
    {
        if ($stub === null) {
            $stub = $this->prepareConf($url, true);
        }
        $this->unsecure($url);

        $this->files->ensureDirExists($this->caPath(), user());

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $caExpireInYears = 20;
        $certificateExpireInDays = 368;
        $caExpireInDate = (new \DateTime())->diff(new \DateTime("+{$caExpireInYears} years"));

        $this->createCa($caExpireInDate->format('%a'));

        $this->createCertificate($url, $certificateExpireInDays);

        $this->createSecureNginxServer($url, $stub);
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     */
    public function unsecure(string $url, bool $preserveUnsecureConfig = false): void
    {
        $stub = null;
        if ($this->files->exists($this->certificatesPath() . '/' . $url . '.crt')) {
            if ($preserveUnsecureConfig) {
                $stub = $this->prepareConf($url);
            }

            $this->files->unlink($this->nginxPath() . '/' . $url);

            $this->files->unlink($this->certificatesPath() . '/' . $url . '.conf');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.key');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.csr');
            $this->files->unlink($this->certificatesPath() . '/' . $url . '.crt');

            $this->cli->run(sprintf('certutil -d sql:$HOME/.pki/nssdb -D -n "%s"', $url));
            $this->cli->run(sprintf('certutil -d $HOME/.mozilla/firefox/*.default -D -n "%s"', $url));
        }
        if ($stub) {
            $this->put($url, $stub);
        }
    }

    /**
     * Regenerate all secured file configurations.
     */
    public function regenerateSecuredSitesConfig(): void
    {
        $this->secured()->each(function ($url) {
            $this->createSecureNginxServer($url);
        });
    }

    /**
     * Get the site URL from a directory if it's a valid Valet site.
     */
    public function getSiteUrl(string $directory): string
    {
        $tld = $this->config->get('domain');

        if ($directory == '.' || $directory == './') { // Allow user to use dot as current directory site `--site=.`
            $directory = $this->host(getcwd());
        }

        $directory = str_replace('.' . $tld, '', $directory); // Remove .tld from site name if it was provided

        if (!$this->parked()->merge($this->links())->where('site', $directory)->count() > 0) {
            throw new \DomainException("The [{$directory}] site could not be found in Valet's site list.");
        }

        return $directory . '.' . $tld;
    }

    /**
     * Create new nginx config or modify existing nginx config to isolate this site
     * to a custom version of PHP.
     */
    public function isolate(string $url, string $phpVersion, bool $secure = false): void
    {
        $stub = $secure ? __DIR__ . '/../stubs/secure.isolated.valet.conf' : __DIR__ . '/../stubs/isolated.valet.conf';

        // Isolate specific variables
        $siteConf = strArrayReplace([
            'VALET_FPM_SOCKET_FILE'      => PhpFpmFacade::fpmSocketFile($phpVersion),
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
     */
    public function removeIsolation(string $valetSite): void
    {
        // If a site has an SSL certificate, we need to keep its custom config file, but we can
        // just re-generate it without defining a custom `valet.sock` file
        if ($this->files->exists($this->certificatesPath() . '/' . $valetSite . '.crt')) {
            $this->createSecureNginxServer($valetSite);
        } else {
            // When site doesn't have SSL, we can remove the custom nginx config file to remove isolation
            $this->files->unlink($this->nginxPath($valetSite));
        }
    }

    /**
     * Extract PHP version of exising nginx config.
     */
    public function customPhpVersion(string $url, string $siteConf = null, bool $returnDecimal = false): ?string
    {
        if ($siteConf === null && !$this->files->exists($this->nginxPath($url))) {
            return null;
        }

        $siteConf = $siteConf ?: $this->files->get($this->nginxPath($url));
        if (strpos($siteConf, '# ' . ISOLATED_PHP_VERSION) !== false) {
            preg_match('/^# ISOLATED_PHP_VERSION=(.*?)\n/m', $siteConf, $version);
            if ($returnDecimal) {
                return $version[1];
            }

            return preg_replace("/[^\d]*/", '', $version[1]); // Example output: "74" or "81"
        }

        return null;
    }

    /**
     * Get PHP version from .valetphprc for a site.
     */
    public function phpRcVersion(string $site): ?string
    {
        if ($site = $this->parked()->merge($this->links())->where('site', $site)->first()) {
            $path = data_get($site, 'path') . '/.valetphprc';

            if ($this->files->exists($path)) {
                return PhpFpmFacade::normalizePhpVersion(trim($this->files->get($path)));
            }
        }
        return null;
    }

    /**
     * Extract Proxy pass of exising nginx config.
     */
    private function getProxyPass(string $url, string $siteConf = null): ?string
    {
        if ($siteConf === null && !$this->files->exists($this->nginxPath($url))) {
            return null;
        }

        $siteConf = $siteConf ?: $this->files->get($this->nginxPath($url));
        preg_match('/proxy_pass (?<host>.*?);/m', $siteConf, $matches);

        return $matches['host'] ?? null;
    }

    private function getNginxConf(string $url): ?string
    {
        if (!$this->files->exists($this->nginxPath() . '/' . $url)) {
            return null;
        }

        return $this->files->get($this->nginxPath() . '/' . $url);
    }

    /**
     * Prepare Nginx Conf based on existing config file.
     */
    private function prepareConf(string $url, bool $requireSecure = false): ?string
    {
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
            $proxyPass = $this->getProxyPass($url, $existingConf);
            if (!$proxyPass) {
                return null;
            }
            $stub = $requireSecure ?
                __DIR__ . '/../stubs/secure.proxy.valet.conf' :
                __DIR__ . '/../stubs/proxy.valet.conf';
            $stub = $this->files->get($stub);

            return strArrayReplace([
                'VALET_PROXY_HOST' => $proxyPass,
            ], $stub);
        }

        if ($stubDetail['stub'] === 'isolated') {
            $phpVersion = $this->customPhpVersion($url, $existingConf, true);
            // empty($stubDetail['tls']) ||  We can use this statement if needed.
            $stub = $requireSecure ?
                __DIR__ . '/../stubs/secure.isolated.valet.conf' :
                __DIR__ . '/../stubs/isolated.valet.conf';
            $stub = $this->files->get($stub);
            // Isolate specific variables
            return strArrayReplace([
                'VALET_FPM_SOCKET_FILE'      => PhpFpmFacade::fpmSocketFile($phpVersion),
                'VALET_ISOLATED_PHP_VERSION' => $phpVersion,
            ], $stub);
        }

        return null;
    }

    /**
     * Identify whether a site is for a proxy by reading the host name from its config file.
     */
    private function getProxyHostForSite(string $site): ?string
    {
        $siteConf = $this->getSiteConfigFileContents($site);

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
     * Get the path to Nginx site configuration files.
     */
    private function nginxPath(string $additionalPath = null): string
    {
        return VALET_HOME_PATH . '/Nginx' . ($additionalPath ? '/' . $additionalPath : '');
    }

    /**
     * Create the given nginx host.
     */
    private function put(string $url, string $siteConf): void
    {
        $this->unsecure($url);

        $this->files->ensureDirExists($this->nginxPath(), user());

        $siteConf = strArrayReplace(
            [
                'VALET_HOME_PATH'     => VALET_HOME_PATH,
                'VALET_SERVER_PATH'   => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX' => VALET_STATIC_PREFIX,
                'VALET_SITE'          => $url,
                'VALET_HTTP_PORT'     => $this->config->get('port', 80),
                'VALET_HTTPS_PORT'    => $this->config->get('https_port', 443),
            ],
            $siteConf
        );

        $this->files->putAsUser(
            $this->nginxPath($url),
            $siteConf
        );
    }

    private function getSiteConfigFileContents(string $site): ?string
    {
        $domain = $this->config->get('domain');
        $suffix = '.' . $domain;
        $file = str_replace($suffix, '', $site) . $suffix;

        return $this->files->exists($this->nginxPath($file)) ? $this->files->get($this->nginxPath($file)) : null;
    }

    /**
     * Get all certificates from config folder.
     */
    private function getCertificates(string $path = null): Collection
    {
        $path = $path ?: $this->certificatesPath();

        return collect($this->files->scanDir($path))->filter(function ($value) {
            return str_ends_with($value, '.crt');
        })->map(function ($cert) {
            return substr($cert, 0, -4);
        })->flip();
    }

    /**
     * Get list of links and present them formatted.
     */
    private function getLinks(string $path, Collection $certs): Collection
    {
        /** @var string $domain */
        $domain = $this->config->get('domain');

        $httpPort = $this->httpSuffix();
        $httpsPort = $this->httpsSuffix();

        return collect($this->files->scanDir($path))->mapWithKeys(function ($site) use ($path) {
            return [$site => $this->files->readLink($path . '/' . $site)];
        })->map(function ($path, $site) use ($certs, $domain, $httpPort, $httpsPort) {
            $secured = $certs->has($site . '.' . $domain);

            $url = \sprintf(
                '%s://%s.%s%s',
                $secured ? 'https' : 'http',
                $site,
                $domain,
                $secured ? $httpsPort : $httpPort
            );
            $phpVersion = $this->getPhpVersion(\sprintf('%s.%s', $site, $domain));

            return [
                'site'       => $site,
                'secured'    => $secured ? ' X' : '',
                'url'        => $url,
                'path'       => $path,
                'phpVersion' => $phpVersion,
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

    /**
     * If CA and root certificates are nonexistent, create them and trust the root cert.
     *
     * @param  int  $caExpireInDays  The number of days the self signed certificate authority is valid.
     */
    private function createCa(int $caExpireInDays): void
    {
        $caPemPath = $this->caPath($this->caCertificatePem);
        $caKeyPath = $this->caPath($this->caCertificateKey);

        if ($this->files->exists($caKeyPath) && $this->files->exists($caPemPath)) {
            $this->trustCa($caPemPath);
            return;
        }

        if ($this->files->exists($caKeyPath)) {
            $this->files->unlink($caKeyPath);
        }
        if ($this->files->exists($caPemPath)) {
            $this->files->unlink($caPemPath);
        }

        $this->untrustCa();

        $this->cli->runAsUser(
            sprintf(
                'openssl req -new -newkey rsa:2048 -days %s -nodes -x509 -subj "/C=/ST=/O=%s/localityName=/commonName=%s/organizationalUnitName=Developers/emailAddress=%s/" -keyout "%s" -out "%s"',
                $caExpireInDays,
                $this->caCertificateOrganization,
                $this->caCertificateCommonName,
                $this->certificateDummyEmail,
                $caKeyPath,
                $caPemPath
            )
        );
        $this->trustCa($caPemPath);
    }

    /**
     * Trust the given root certificate file in the macOS Keychain.
     */
    private function untrustCa(): void
    {
        $this->files->remove(\sprintf('%s%s.crt', $this->caCertificatePath, $this->caCertificatePem));
        $this->cli->run('sudo update-ca-certificates');
    }

    /**
     * Trust the given root certificate file in the macOS Keychain.
     */
    private function trustCa(string $caPemPath): void
    {
        $this->files->copy($caPemPath, \sprintf('%s%s.crt', $this->caCertificatePath, $this->caCertificatePem));
        $this->cli->run('sudo update-ca-certificates');

        $this->cli->runAsUser(sprintf(
            'certutil -d sql:$HOME/.pki/nssdb -A -t TC -n "%s" -i "%s"',
            $this->caCertificateOrganization,
            $caPemPath
        ));

        $this->cli->runAsUser(sprintf(
            'certutil -d $HOME/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
            $this->caCertificateOrganization,
            $caPemPath
        ));

        $this->cli->runAsUser(sprintf(
            'certutil -d $HOME/snap/firefox/common/.mozilla/firefox/*.default -A -t TC -n "%s" -i "%s"',
            $this->caCertificateOrganization,
            $caPemPath
        ));
    }

    /**
     * Create and trust a certificate for the given URL.
     */
    private function createCertificate(string $url, int $certificateExpireInDays = 368): void
    {
        $caPemPath = $this->caPath($this->caCertificatePem);
        $caKeyPath = $this->caPath($this->caCertificateKey);
        $caSrlPath = $this->caPath($this->caCertificateSrl);

        $keyPath = $this->certificatesPath() . '/' . $url . '.key';
        $csrPath = $this->certificatesPath() . '/' . $url . '.csr';
        $crtPath = $this->certificatesPath() . '/' . $url . '.crt';
        $confPath = $this->certificatesPath() . '/' . $url . '.conf';

        $this->buildCertificateConf($confPath, $url);
        $this->createPrivateKey($keyPath);
        $this->createSigningRequest($url, $keyPath, $csrPath, $confPath);

        $caSrlParam = '-CAserial "' . $caSrlPath . '"';
        if (! $this->files->exists($caSrlPath)) {
            $caSrlParam .= ' -CAcreateserial';
        }

        $this->cli->run(sprintf(
            'openssl x509 -req -sha256 -days %s -CA "%s" -CAkey "%s" %s -in %s -out %s -extensions v3_req -extfile %s',
            $certificateExpireInDays,
            $caPemPath,
            $caKeyPath,
            $caSrlParam,
            $csrPath,
            $crtPath,
            $confPath
        ));
    }

    /**
     * Create the private key for the TLS certificate.
     */
    private function createPrivateKey(string $keyPath): void
    {
        $this->cli->runAsUser(sprintf('openssl genrsa -out %s 2048', $keyPath));
    }

    /**
     * Create the signing request for the TLS certificate.
     */
    private function createSigningRequest(string $url, string $keyPath, string $csrPath, string $confPath): void
    {
        $this->cli->runAsUser(sprintf(
            'openssl req -new -key %s -out %s -subj "/C=/ST=/O=/localityName=/commonName=%s/organizationalUnitName=/emailAddress=%s/" -config %s',
            $keyPath,
            $csrPath,
            $url,
            $this->certificateDummyEmail,
            $confPath
        ));
    }

    /**
     * Build the SSL config for the given URL.
     */
    private function buildCertificateConf(string $path, string $url): void
    {
        $config = str_replace('VALET_DOMAIN', $url, $this->files->get(__DIR__ . '/../stubs/openssl.conf'));
        $this->files->putAsUser($path, $config);
    }

    private function createSecureNginxServer(string $url, string $stub = null): void
    {
        $this->files->putAsUser(
            $this->nginxPath($url),
            $this->buildSecureNginxServer($url, $stub)
        );
    }

    /**
     * Build the TLS secured Nginx server for the given URL.
     */
    private function buildSecureNginxServer(string $url, ?string $stub = null): string
    {
        $stub = ($stub ?: $this->files->get(__DIR__ . '/../stubs/secure.valet.conf'));
        $path = $this->certificatesPath();

        return strArrayReplace(
            [
                'VALET_HOME_PATH'       => VALET_HOME_PATH,
                'VALET_SERVER_PATH'     => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX'   => VALET_STATIC_PREFIX,
                'VALET_SITE'            => $url,
                'VALET_CERT'            => $path . '/' . $url . '.crt',
                'VALET_KEY'             => $path . '/' . $url . '.key',
                'VALET_HTTP_PORT'       => $this->config->get('port', 80),
                'VALET_HTTPS_PORT'      => $this->config->get('https_port', 443),
                'VALET_REDIRECT_PORT'   => $this->httpsSuffix(),
                'VALET_FPM_SOCKET_FILE' => PhpFpmFacade::fpmSocketFile(PhpFpmFacade::getCurrentVersion()),
            ],
            $stub
        );
    }

    /**
     * Get the path to the linked Valet sites.
     */
    private function sitesPath(): string
    {
        return VALET_HOME_PATH . '/Sites';
    }

    /**
     * Get the path to the Valet TLS certificates.
     */
    private function certificatesPath(): string
    {
        return VALET_HOME_PATH . '/Certificates';
    }

    /**
     * Get the path to the Valet CA certificates.
     */
    public function caPath(?string $caFile = null): string
    {
        return VALET_HOME_PATH . '/CA' . ($caFile ? '/' . $caFile : '');
    }

    /**
     * Get list of sites and return them formatted
     * Will work for symlink and normal site paths.
     */
    private function getSites(string $path, Collection $certs): Collection
    {
        /** @var string $domain */
        $domain = $this->config->get('domain');

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
        })->map(function ($path, $site) use ($certs, $domain) {
            $secured = $certs->has($site);
            $url = \sprintf('%s://%s.%s', $secured ? 'https' : 'http', $site, $domain);
            $phpVersion = $this->getPhpVersion(\sprintf('%s.%s', $site, $domain));

            return [
                'site'       => $site,
                'secured'    => $secured ? ' X' : '',
                'url'        => $url,
                'path'       => $path,
                'phpVersion' => $phpVersion,
            ];
        });
    }

    /**
     * Get the PHP version for the given site.
     */
    private function getPhpVersion(string $url): string
    {
        $defaultPhpVersion = PhpFpmFacade::getCurrentVersion();
        $customPhpVersion = $this->customPhpVersion($url);
        return PhpFpmFacade::normalizePhpVersion($customPhpVersion ?? $defaultPhpVersion);
    }

    private function parked(): Collection
    {
        $certs = $this->getCertificates();

        $links = $this->getSites($this->sitesPath(), $certs);

        /** @var array $paths */
        $paths = $this->config->get('paths');
        $parkedLinks = collect();
        foreach (array_reverse($paths) as $path) {
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
}
