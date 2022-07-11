<?php

use Illuminate\Container\Container;
use Tightenco\Collect\Support\Collection;

/**
 * Class Facade.
 */
class Facade
{
    /**
     * The key for the binding in the container.
     *
     * @return string
     */
    public static function containerKey()
    {
        return 'Valet\\'.basename(str_replace('\\', '/', get_called_class()));
    }

    /**
     * Call a non-static method on the facade.
     *
     * @param string $method
     * @param array  $parameters
     *
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        $resolvedInstance = Container::getInstance()->make(static::containerKey());

        return call_user_func_array([$resolvedInstance, $method], $parameters);
    }
}

/**
 * Class Nginx.
 *
 * @method static void install()
 * @method static void installConfiguration()
 * @method static void installServer()
 * @method static void installNginxDirectory()
 * @method static void updatePort(string $newPort)
 * @method static void rewriteSecureNginxFiles()
 * @method static void restart()
 * @method static void stop()
 * @method static void status()
 * @method static void uninstall()
 */
class Nginx extends Facade
{
}

/**
 * Class CommandLine.
 *
 * @method static void quietly($command)
 * @method static void quietlyAsUser($command)
 * @method static void passthru($command)
 * @method static string run(string $command, callable $onError = null)
 * @method static string runAsUser(string $command, callable $onError = null)
 * @method static string runCommand(string $command, callable $onError = null)
 */
class CommandLine extends Facade
{
}

/**
 * Class Configuration.
 *
 * @method static void install()
 * @method static void uninstall()
 * @method static void createConfigurationDirectory()
 * @method static void createDriversDirectory()
 * @method static void createSitesDirectory()
 * @method static void createExtensionsDirectory()
 * @method static void createLogDirectory()
 * @method static void createCertificatesDirectory()
 * @method static void writeBaseConfiguration()
 * @method static void addPath(string $path, bool $prepend = false)
 * @method static void prependPath(string $path)
 * @method static void removePath(string $path)
 * @method static void prune()
 * @method static array read()
 * @method static mixed get(string $key, mixed $default = null)
 * @method static array updateKey(string $key, mixed $value)
 * @method static void write(array $config)
 * @method static string path()
 */
class Configuration extends Facade
{
}

/**
 * Class DnsMasq.
 *
 * @method static void install(string $domain)
 * @method static void stop()
 * @method static void restart()
 * @method static void createCustomConfigFile(string $domain)
 * @method static void fixResolved()
 * @method static void dnsmasqSetup()
 * @method static void updateDomain(string $newDomain)
 * @method static void uninstall()
 */
class DnsMasq extends Facade
{
}

/**
 * Class Filesystem.
 *
 * @method static ArrayObject toIterator($files)
 * @method static void remove(string $files)
 * @method static bool isDir(string $path)
 * @method static void mkdir(string $path, string $owner = null, int $mode = 0755)
 * @method static void ensureDirExists(string $path, string $owner = null, int $mode = 0755)
 * @method static void mkdirAsUser(string $path, int $mode = 0755)
 * @method static string touch(string $path, string $owner = null)
 * @method static void touchAsUser(string $path)
 * @method static bool exists(string $files)
 * @method static string get(string $path)
 * @method static string put(string $path, string $contents, string $owner = null)
 * @method static string putAsUser(string $path, string $contents)
 * @method static void append(string $path, string $contents, string $owner = null)
 * @method static void appendAsUser(string $path, string $contents)
 * @method static void copy(string $from, string $to)
 * @method static bool copyAsUser(string $from, string $to)
 * @method static bool backup(string $file)
 * @method static bool restore(string $file)
 * @method static void symlink(string $target, string $link)
 * @method static void symlinkAsUser(string $target, string $link)
 * @method static void commentLine(string $line, string $file)
 * @method static void uncommentLine(string $line, string $file)
 * @method static void unlink(string $path)
 * @method static void chown(string $path, string $user)
 * @method static void chgrp(string $path, string $group)
 * @method static string realpath(string $path)
 * @method static bool isLink(string $path)
 * @method static string readLink(string $path)
 * @method static void removeBrokenLinksAt(string $path)
 * @method static bool isBrokenLink(string $path)
 * @method static array scandir(string $path)
 */
class Filesystem extends Facade
{
}

/**
 * Class Ngrok.
 *
 * @method static string currentTunnelUrl()
 * @method static string|null findHttpTunnelUrl(array $tunnels)
 */
class Ngrok extends Facade
{
}

/**
 * Class PhpFpm.
 *
 * @method static void install()
 * @method static void uninstall()
 * @method static void changeVersion(string $version = null, bool $updateCli = null)
 * @method static void installConfiguration()
 * @method static void restart()
 * @method static void stop()
 * @method static void status()
 * @method static string getVersion(bool $real = false)
 * @method static string fpmServiceName()
 * @method static string fpmConfigPath()
 */
class PhpFpm extends Facade
{
}

/**
 * Class Site.
 *
 * @method static string|null host(string $path)
 * @method static string link(string $target, string $link)
 * @method static Collection links()
 * @method static Collection getCertificates(string $path)
 * @method static array getLinks(string $path, Collection $certs)
 * @method static string httpSuffix()
 * @method static string httpsSuffix()
 * @method static void unlink(string $name)
 * @method static void pruneLinks()
 * @method static void resecureForNewDomain(string $oldDomain, string $domain)
 * @method static Collection secured()
 * @method static void secure(string $url, string $stub = null)
 * @method static void createCertificate(string $url)
 * @method static void createPrivateKey(string $keyPath)
 * @method static void createSigningRequest(string $url, string $keyPath, string $csrPath, string $confPath)
 * @method static void buildCertificateConf(string $path, string $url)
 * @method static void trustCertificate(string $crtPath, string $url)
 * @method static void createSecureNginxServer(string $url, string $stub = null)
 * @method static void buildSecureNginxServer(string $url, string $stub = null)
 * @method static void unsecure(string $url)
 * @method static void regenerateSecuredSitesConfig()
 * @method static string sitesPath()
 * @method static string certificatesPath()
 */
class Site extends Facade
{
}

/**
 * Class Valet.
 *
 * @method static void symlinkToUsersBin()
 * @method static void uninstall()
 * @method static array extensions()
 * @method static bool onLatestVersion(string $currentVersion)
 * @method static string getLatestVersion()
 * @method static void environmentSetup()
 * @method static void packageManagerSetup()
 * @method static string getAvailablePackageManager()
 * @method static void serviceManagerSetup()
 * @method static string getAvailableServiceManager()
 */
class Valet extends Facade
{
}

/**
 * Class Requirements.
 *
 * @method static self setIgnoreSELinux(bool $ignore = true)
 * @method static void check()
 * @method static void homePathIsInsideRoot()
 * @method static void seLinuxIsEnabled()
 */
class Requirements extends Facade
{
}

/**
 * Class MailHog.
 *
 * @method static void install()
 * @method static void ensureInstalled()
 * @method static void createService()
 * @method static void updateDomain()
 * @method static bool isAvailable(string $newPort)
 * @method static void start()
 * @method static void restart()
 * @method static void stop()
 * @method static void status()
 * @method static void uninstall()
 */
class Mailhog extends Facade
{
}

/**
 * Class ValetRedis.
 *
 * @method static void install()
 * @method static bool installed()
 * @method static void uninstall()
 * @method static void restart()
 * @method static void stop()
 */
class ValetRedis extends Facade
{
}

/**
 * Class CliPrompt.
 *
 * @method static string prompt($question, $hidden = false, $suggestion = null, $default = null)
 */
class CliPrompt extends Facade
{
}

/**
 * Class Mysql.
 *
 * @method static void install()
 * @method static void stop()
 * @method static void restart()
 * @method static void uninstall()
 * @method static void setRootPassword(string $oldPwd, string $newPwd)
 * @method static void listDatabases()
 * @method static void importDatabase(string $file, string $database, bool $isDatabaseExists)
 * @method static string getDirName()
 * @method static bool dropDatabase(string $name)
 * @method static bool|string createDatabase(string $name)
 * @method static bool isDatabaseExists(string $name)
 * @method static array exportDatabase(string $database, bool $exportSql = false)
 */
class Mysql extends Facade
{
}
