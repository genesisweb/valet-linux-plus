<?php

namespace Valet;

use Illuminate\Support\Collection;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\Traits\Paths;

class SiteSecure
{
    use Paths;

    private Filesystem $files;
    private CommandLine $cli;
    private Configuration $config;

    private string $caCertificatePath = '/usr/local/share/ca-certificates/';
    private string $caCertificatePem = 'ValetLinuxCASelfSigned.pem';
    private string $caCertificateKey = 'ValetLinuxCASelfSigned.key';
    private string $caCertificateSrl = 'ValetLinuxCASelfSigned.srl';
    private string $caCertificateOrganization = 'Valet Linux CA Self Signed Organization';
    private string $caCertificateCommonName = 'Valet Linux CA Self Signed CN';
    private string $certificateDummyEmail = 'certificate@valet.linux';

    public function __construct(Filesystem $filesystem, CommandLine $cli, Configuration $config)
    {
        $this->files = $filesystem;
        $this->cli = $cli;
        $this->config = $config;
    }

    /**
     * Secure the given host with TLS.
     */
    public function secure(string $url, string $stub = null): void
    {
        if ($stub === null) {
            $stub = $this->prepareConf($url, true);
        }

        $this->files->ensureDirExists($this->caPath(), user());

        $this->files->ensureDirExists($this->certificatesPath(), user());

        $caExpireInDate = (new \DateTime())->diff(new \DateTime("+20 years"));
        $expiryInDays = (int)$caExpireInDate->format('%a'); // 20 years in days
        $this->createCa($expiryInDays);

        $certificateExpireInDate = (new \DateTime())->diff(new \DateTime("+1 year"));
        $certificateExpireInDays = (int)$certificateExpireInDate->format('%a'); // 20 years in days
        $this->createCertificate($url, $certificateExpireInDays);

        $this->files->putAsUser(
            $this->nginxPath($url),
            $this->buildSecureNginxServer($url, $stub)
        );
    }

    /**
     * Unsecure the given URL so that it will use HTTP again.
     */
    public function unsecure(string $url, bool $preserveUnsecureConfig = false): void
    {
        $stub = null;
        if ($this->files->exists($this->certificatesPath($url . '.crt'))) {
            if ($preserveUnsecureConfig) {
                $stub = $this->prepareConf($url);
            }

            $this->files->unlink($this->nginxPath($url));

            $this->files->unlink($this->certificatesPath($url . '.conf'));
            $this->files->unlink($this->certificatesPath($url . '.key'));
            $this->files->unlink($this->certificatesPath($url . '.csr'));
            $this->files->unlink($this->certificatesPath($url . '.crt'));
        }

        if ($stub) {
            $stub = $this->buildUnsecureNginxServer($url, $stub);

            $this->files->putAsUser(
                $this->nginxPath($url),
                $stub
            );
        }
    }

    /**
     * Get all the URLs that are currently secured.
     * @return Collection<int, string>
     */
    public function secured(): Collection
    {
        return collect($this->files->scandir($this->certificatesPath()))
            ->map(function ($file) {
                return str_replace(['.key', '.csr', '.crt', '.conf'], '', $file);
            })->unique()->values();
    }

    /**
     * Regenerate all secured file configurations.
     */
    public function regenerateSecuredSitesConfig(): void
    {
        $this->secured()->each(function (string $url) {
            $this->files->putAsUser(
                $this->nginxPath($url),
                $this->buildSecureNginxServer($url)
            );
        });
    }

    /**
     * Re-secure all currently secured sites with a fresh domain.
     */
    public function reSecureForNewDomain(string $oldDomain, string $domain): void
    {
        if (!$this->files->exists($this->certificatesPath())) {
            return;
        }

        $secured = $this->secured();

        foreach ($secured as $oldUrl) {
            $newUrl = str_replace('.' . $oldDomain, '.' . $domain, $oldUrl);
            $hasConf = $this->files->exists($this->nginxPath($oldUrl));
            $nginxConf = null;
            if ($hasConf) {
                $nginxConf = $this->files->get($this->nginxPath($oldUrl));
                $nginxConf = str_replace($oldUrl, $newUrl, $nginxConf);
            }

            $this->unsecure($oldUrl);

            $this->secure($newUrl, $nginxConf);
        }
    }

    /**
     * If CA and root certificates are nonexistent, create them and trust the root cert.
     *
     * @param int $caExpireInDays The number of days the self-signed certificate authority is valid.
     * @throws \Exception
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

        $this->unTrustCa();

        $subject = sprintf(
            '/C=/ST=/O=%s/localityName=/commonName=%s/organizationalUnitName=Developers/emailAddress=%s/',
            $this->caCertificateOrganization,
            $this->caCertificateCommonName,
            $this->certificateDummyEmail,
        );
        $this->cli->runAsUser(
            sprintf(
                'openssl req -new -newkey rsa:2048 -days %s -nodes -x509 -subj "%s" -keyout "%s" -out "%s"',
                $caExpireInDays,
                $subject,
                $caKeyPath,
                $caPemPath
            )
        );
        $this->trustCa($caPemPath);
    }

    /**
     * Trust the given root certificate file in the macOS Keychain.
     * @throws \Exception
     */
    private function unTrustCa(): void
    {
        $this->files->remove(\sprintf('%s%s.crt', $this->caCertificatePath, $this->caCertificatePem));
        $this->cli->run('sudo update-ca-certificates');
    }

    /**
     * Trust the given root certificate file in the macOS Keychain.
     */
    private function trustCa(string $caPemPath): void
    {
        $this->files->copy($caPemPath, sprintf('%s%s.crt', $this->caCertificatePath, $this->caCertificatePem));
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

        $this->generateCertificateConf($confPath, $url);
        $this->cli->runAsUser(sprintf('openssl genrsa -out %s 2048', $keyPath));

        $subject = sprintf(
            '/C=/ST=/O=/localityName=/commonName=%s/organizationalUnitName=/emailAddress=%s/',
            $url,
            $this->certificateDummyEmail,
        );
        $this->cli->runAsUser(sprintf(
            'openssl req -new -key %s -out %s -subj "%s" -config %s',
            $keyPath,
            $csrPath,
            $subject,
            $confPath
        ));

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
     * Build the TLS secured Nginx server for the given URL.
     */
    public function buildSecureNginxServer(string $url, ?string $stub = null): string
    {
        $stub = ($stub ?: $this->files->get(VALET_ROOT_PATH . '/cli/stubs/secure.valet.conf'));
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
     * Build the TLS secured Nginx server for the given URL.
     */
    public function buildUnsecureNginxServer(string $url, string $stub): string
    {
        $this->files->ensureDirExists($this->nginxPath(), user());

        return strArrayReplace(
            [
                'VALET_HOME_PATH'     => VALET_HOME_PATH,
                'VALET_SERVER_PATH'   => VALET_SERVER_PATH,
                'VALET_STATIC_PREFIX' => VALET_STATIC_PREFIX,
                'VALET_SITE'          => $url,
                'VALET_HTTP_PORT'     => $this->config->get('port', 80),
                'VALET_HTTPS_PORT'    => $this->config->get('https_port', 443),
            ],
            $stub
        );
    }

    /**
     * Prepare Nginx Conf based on existing config file.
     */
    private function prepareConf(string $url, bool $secure = false): ?string
    {
        if (!$this->files->exists($this->nginxPath($url))) {
            return null;
        }

        $existingConf = $this->files->get($this->nginxPath($url));

        preg_match('/# valet stub: (?<tls>secure)?\.?(?<stub>.*?).valet.conf/m', $existingConf, $stubDetail);

        if (empty($stubDetail['stub'])) {
            return null;
        }

        if ($stubDetail['stub'] === 'proxy') {
            // Find proxy_pass from existingConf.
            $proxyPass = $this->getProxyPass($url, $existingConf);
            if (!$proxyPass) {
                return null;
            }
            $stub = $secure ?
                VALET_ROOT_PATH . '/cli/stubs/secure.proxy.valet.conf' :
                VALET_ROOT_PATH . '/cli/stubs/proxy.valet.conf';
            $stub = $this->files->get($stub);

            return strArrayReplace([
                'VALET_PROXY_HOST' => $proxyPass,
            ], $stub);
        }

        if ($stubDetail['stub'] === 'isolated') {
            $phpVersion = $this->isolatedPhpVersion($existingConf);
            // empty($stubDetail['tls']) ||  We can use this statement if needed.
            $stub = $secure ?
                VALET_ROOT_PATH . '/cli/stubs/secure.isolated.valet.conf' :
                VALET_ROOT_PATH . '/cli/stubs/isolated.valet.conf';
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

    /**
     * Build the SSL config for the given URL.
     */
    private function generateCertificateConf(string $path, string $url): void
    {
        $config = str_replace('VALET_DOMAIN', $url, $this->files->get(VALET_ROOT_PATH . '/cli/stubs/openssl.conf'));
        $this->files->putAsUser($path, $config);
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
     * Extract PHP version of exising nginx config.
     */
    private function isolatedPhpVersion(string $siteConf): string
    {
        if (str_contains($siteConf, '# ' . ISOLATED_PHP_VERSION)) {
            preg_match('/^# ISOLATED_PHP_VERSION=(.*?)\n/m', $siteConf, $version);
            return $version[1];
        }

        return '';
    }
}
