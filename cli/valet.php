#!/usr/bin/env php
<?php

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
} else {
    require __DIR__.'/../../../autoload.php';
}

use Illuminate\Container\Container;
use Silly\Application;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Create the application.
 */
Container::setInstance(new Container());

$version = 'v1.6.1';

$app = new Application('ValetLinux+', $version);

/**
 * Detect environment.
 */
Valet::environmentSetup();

/**
 * Allow Valet to be run more conveniently by allowing the Node proxy to run password-less sudo.
 */
$app->command('install [--ignore-selinux] [--mariadb]', function ($ignoreSELinux, $mariaDB) {
    passthru(dirname(__FILE__).'/scripts/update.sh'); // Clean up cruft
    Requirements::setIgnoreSELinux($ignoreSELinux)->check();
    Configuration::install();
    Nginx::install();
    PhpFpm::install();
    DnsMasq::install(Configuration::read()['domain']);
    Valet::symlinkToUsersBin();
    Mailhog::install();
    ValetRedis::install();
    Nginx::restart();
    Mysql::install($mariaDB);

    output(PHP_EOL.'<info>Valet installed successfully!</info>');
})->descriptions('Install the Valet services', [
    '--ignore-selinux' => 'Skip SELinux checks',
]);

/**
 * Most commands are available only if valet is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Prune missing directories and symbolic links on every command.
     */
    Configuration::prune();
    Site::pruneLinks();

    /**
     * Get or set the domain currently being used by Valet.
     */
    $app->command('domain [domain]', function ($domain = null) {
        if ($domain === null) {
            info(Configuration::read()['domain']);

            return;
        }

        DnsMasq::updateDomain($domain = trim($domain, '.'));
        $oldDomain = Configuration::read()['domain'];

        Configuration::updateKey('domain', $domain);
        Site::resecureForNewDomain($oldDomain, $domain);
        Mailhog::updateDomain();
        PhpFpm::restart();
        Nginx::restart();

        info('Your Valet domain has been updated to ['.$domain.'].');
    })->descriptions('Get or set the domain used for Valet sites');

    /**
     * Get or set the port number currently being used by Valet.
     */
    $app->command('port [port] [--https]', function ($port, $https) {
        if ($port === null) {
            info('Current Nginx port (HTTP): '.Configuration::get('port', 80));
            info('Current Nginx port (HTTPS): '.Configuration::get('https_port', 443));

            return;
        }

        $port = trim($port);

        if ($https) {
            Configuration::updateKey('https_port', $port);
        } else {
            Nginx::updatePort($port);
            Configuration::updateKey('port', $port);
        }

        Site::regenerateSecuredSitesConfig();

        Nginx::restart();
        PhpFpm::restart();

        $protocol = $https ? 'HTTPS' : 'HTTP';
        info("Your Nginx {$protocol} port has been updated to [{$port}].");
    })->descriptions('Get or set the port number used for Valet sites');

    /**
     * Determine if the site is secured or not.
     */
    $app->command('secured [site]', function ($site) {
        if (Site::secured()->contains($site)) {
            info("{$site} is secured.");

            return 1;
        }

        info("{$site} is not secured.");

        return 0;
    })->descriptions('Determine if the site is secured or not');

    /**
     * Create Nginx proxy config for the specified domain.
     */
    $app->command('proxy domain host [--secure]', function ($domain, $host, $secure) {
        Site::proxyCreate($domain, $host, $secure);
        Nginx::restart();
    })->descriptions('Create an Nginx proxy site for the specified host. Useful for docker, node etc.', [
        '--secure' => 'Create a proxy with a trusted TLS certificate',
    ]);

    /**
     * Delete Nginx proxy config.
     */
    $app->command('unproxy domain', function ($domain) {
        Site::proxyDelete($domain);
        Nginx::restart();
    })->descriptions('Delete an Nginx proxy config.');

    /**
     * Display all the sites that are proxies.
     */
    $app->command('proxies', function () {
        $proxies = Site::proxies();

        table(['URL', 'SSL', 'Host'], $proxies->all());
    })->descriptions('Display all of the proxy sites');

    /**
     * Add the current working directory to paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        Configuration::addPath($path ?: getcwd());

        info(($path === null ? 'This' : "The [{$path}]")." directory has been added to Valet's paths.");
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Remove the current working directory from paths configuration.
     */
    $app->command('forget [path]', function ($path = null) {
        Configuration::removePath($path ?: getcwd());

        info(($path === null ? 'This' : "The [{$path}]")." directory has been removed from Valet's paths.");
    })->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Remove the current working directory to paths configuration.
     */
    $app->command('status', function () {
        PhpFpm::status();
        Nginx::status();
    })->descriptions('View Valet service status');

    /**
     * Register a symbolic link with Valet.
     */
    $app->command('link [name]', function ($name) {
        $linkPath = Site::link(getcwd(), $name = $name ?: basename(getcwd()));

        info('A ['.$name.'] symbolic link has been created in ['.$linkPath.'].');
    })->descriptions('Link the current working directory to Valet');

    /**
     * Display all the registered symbolic links.
     */
    $app->command('links', function () {
        $links = Site::links();

        table(['Site', 'SSL', 'URL', 'Path'], $links->all());
    })->descriptions('Display all of the registered Valet links');

    /**
     * Unlink a link from the Valet links directory.
     */
    $app->command('unlink [name]', function ($name) {
        Site::unlink($name = $name ?: basename(getcwd()));

        info('The ['.$name.'] symbolic link has been removed.');
    })->descriptions('Remove the specified Valet link');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain]', function ($domain = null) {
        $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['domain'];

        Site::secure($url);
        PhpFpm::restart();
        Nginx::restart();

        info('The ['.$url.'] site has been secured with a fresh TLS certificate.');
    })->descriptions('Secure the given domain with a trusted TLS certificate');

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain]', function ($domain = null) {
        $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['domain'];

        Site::unsecure($url);
        PhpFpm::restart();
        Nginx::restart();

        info('The ['.$url.'] site will now serve traffic over HTTP.');
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

    /**
     * Register a subdomain link.
     */
    $app->command('subdomain:create [name] [--secure]', function ($name, $secure) {
        $name = $name ?: 'www';
        Site::link(getcwd(), $name.'.'.basename(getcwd()));

        if ($secure) {
            $this->runCommand('secure '.$name);
        }
        $domain = Configuration::read()['domain'];

        info('Subdomain '.$name.'.'.basename(getcwd()).'.'.$domain.' created');
    })->descriptions('Create a subdomains');

    /**
     * Unregister a subdomain link.
     */
    $app->command('subdomain:remove [name]', function ($name) {
        $name = $name ?: 'www';
        Site::unlink($name.'.'.basename(getcwd()));
        $domain = Configuration::read()['domain'];
        info('Subdomain '.$name.'.'.basename(getcwd()).'.'.$domain.' removed');
    })->descriptions('Remove a subdomains');

    /**
     * List subdomains.
     */
    $app->command('subdomain:list', function () {
        $links = Site::links();
        table(['Site', 'SSL', 'URL', 'Path'], $links->all());
    })->descriptions('List all subdomains');

    /**
     * Determine which Valet driver the current directory is using.
     */
    $app->command('which', function () {
        require __DIR__.'/drivers/require.php';

        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            info('This site is served by ['.get_class($driver).'].');
        } else {
            warning('Valet could not determine which driver to use for this site.');
        }
    })->descriptions('Determine which Valet driver serves the current working directory');

    /**
     * Display all the registered paths.
     */
    $app->command('paths', function () {
        $paths = Configuration::read()['paths'];

        if (count($paths) > 0) {
            info(json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            warning('No paths have been registered.');
        }
    })->descriptions('Get all of the paths registered with Valet');

    /**
     * Open the current directory in the browser.
     */
    $app->command('open [domain]', function ($domain = null) {
        $url = 'http://'.($domain ?: Site::host(getcwd())).'.'.Configuration::read()['domain'].'/';

        passthru('xdg-open '.escapeshellarg($url));
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    $app->command('share', function () {
        warning(
            'It looks like you are running `cli/valet.php` directly,
            please use the `valet` script in the project root instead.'
        );
    })->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url', function () {
        output(Ngrok::currentTunnelUrl());
    })->descriptions('Get the URL to the current Ngrok tunnel');

    /**
     * Start the daemon services.
     */
    $app->command('start [services]*', function ($services) {
        if (empty($services)) {
            DnsMasq::restart();
            PhpFpm::restart();
            Nginx::restart();
            Mailhog::restart();
            Mysql::restart();
            ValetRedis::restart();
            info('Valet services have been started.');

            return;
        }
        foreach ($services as $service) {
            switch ($service) {
                case 'nginx':
                    Nginx::restart();
                    break;

                case 'php':
                    PhpFpm::restart();
                    break;

                case 'mailhog':
                    Mailhog::restart();
                    break;

                case 'dnsmasq':
                    DnsMasq::restart();
                    break;

                case 'mysql':
                    Mysql::restart();
                    break;

                case 'redis':
                    ValetRedis::restart();
                    break;
                default:
                    break;
            }
        }

        info('Specified Valet services have been started.');
    })->descriptions('Start the Valet services');

    /**
     * Restart the daemon services.
     */
    $app->command('restart [services]*', function ($services) {
        if (empty($services)) {
            DnsMasq::restart();
            PhpFpm::restart();
            Nginx::restart();
            Mailhog::restart();
            Mysql::restart();
            ValetRedis::restart();
            info('Valet services have been restarted.');

            return;
        }

        foreach ($services as $service) {
            switch ($service) {
                case 'nginx':
                    Nginx::restart();
                    break;

                case 'php':
                    PhpFpm::restart();
                    break;

                case 'mailhog':
                    Mailhog::restart();
                    break;

                case 'dnsmasq':
                    DnsMasq::restart();
                    break;

                case 'mysql':
                    Mysql::restart();
                    break;

                case 'redis':
                    ValetRedis::restart();
                    break;
                default:
                    break;
//                case 'elasticsearch': {
//                    Elasticsearch::restart();
//                    break;
//                }
//                case 'rabbitmq': {
//                    RabbitMq::restart();
//                    break;
//                }
//                case 'varnish': {
//                    Varnish::restart();
//                    break;
//                }
            }
        }

        info('Specified Valet services have been restarted.');
    })->descriptions('Restart the Valet services');

    /**
     * Stop the daemon services.
     */
    $app->command('stop [services]*', function ($services) {
        if (empty($services)) {
            PhpFpm::stop();
            Nginx::stop();
            Mailhog::stop();
            Mysql::stop();
            ValetRedis::stop();
            info('Valet services have been stopped.');

            return;
        }

        foreach ($services as $service) {
            switch ($service) {
                case 'nginx':
                    Nginx::stop();
                    break;

                case 'php':
                    PhpFpm::stop();
                    break;

                case 'mailhog':
                    Mailhog::stop();
                    break;

                case 'mysql':
                    Mysql::stop();
                    break;

                case 'redis':
                    ValetRedis::stop();
                    break;
                default:
                    break;

            }
        }

        info('Specified Valet services have been stopped.');
    })->descriptions('Stop the Valet services');

    /**
     * Uninstall Valet entirely.
     */
    $app->command('uninstall', function () {
        Nginx::uninstall();
        PhpFpm::uninstall();
        DnsMasq::uninstall();
        Mailhog::uninstall();
        Configuration::uninstall();
        Valet::uninstall();

        info('Valet has been uninstalled.');
    })->descriptions('Uninstall the Valet services');

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('update', function () use ($version) {
        $script = dirname(__FILE__).'/scripts/update.sh';

        if (Valet::onLatestVersion($version)) {
            info('You have the latest version of Valet Linux');
            passthru($script);
        } else {
            warning('There is a new release of Valet Linux');
            warning('Updating now...');
            $latestVersion = Valet::getLatestVersion();
            if ($latestVersion) {
                passthru($script." update $latestVersion");
            } else {
                passthru($script.' update');
            }
        }
    })->descriptions('Update Valet Linux and clean up cruft');

    /**
     * Change the PHP version to the desired one.
     */
    $app->command('use [preferedversion] [--update-cli] [--install-ext]', function (
        $preferedVersion = null,
        $updateCli = null,
        $installExt = null)
    {
        info('Changing php-fpm version...');
        PhpFpm::changeVersion($preferedVersion, $updateCli, $installExt);
        info('php-fpm version successfully changed! 🎉');
    })->descriptions(
        'Set the PHP-fpm version to use, enter "default" or leave empty to use version: '
        . PhpFpm::getVersion(true),
        [
            '--update-cli' => 'Updates CLI version as well',
        ]
    );

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('is-latest', function () use ($version) {
        if (Valet::onLatestVersion($version)) {
            output('YES');
        } else {
            output('NO');
        }
    })->descriptions('Determine if this is the latest version of Valet');

    /**
     * List MySQL Database.
     */
    $app->command('db:list', function () {
        Mysql::listDatabases();
    })->descriptions('List all available database in MySQL/MariaDB');

    /**
     * Create new database in MySQL.
     */
    $app->command('db:create [database_name]', function ($databaseName) {
        Mysql::createDatabase($databaseName);
    })->descriptions('Create new database in MySQL/MariaDB');

    /**
     * Drop database in MySQL.
     */
    $app->command('db:drop [database_name] [-y|--yes]', function (Input $input, $output, $databaseName) {
        $helper = $this->getHelperSet()->get('question');
        $defaults = $input->getOptions();
        if (!$defaults['yes']) {
            $question = new ConfirmationQuestion('Are you sure you want to delete the database? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                warning('Aborted');

                return;
            }
        }
        Mysql::dropDatabase($databaseName);
    })->descriptions('Drop given database from MySQL/MariaDB');

    /**
     * Reset database in MySQL.
     */
    $app->command('db:reset [database_name] [-y|--yes]', function (Input $input, $output, $databaseName) {
        $helper = $this->getHelperSet()->get('question');
        $defaults = $input->getOptions();
        if (!$defaults['yes']) {
            $question = new ConfirmationQuestion('Are you sure you want to reset the database? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                warning('Aborted');

                return;
            }
        }
        $dropDB = Mysql::dropDatabase($databaseName);
        if (!$dropDB) {
            warning('Error resetting database');

            return;
        }

        $databaseName = Mysql::createDatabase($databaseName);

        if (!$databaseName) {
            warning('Error resetting database');

            return;
        }

        info("Database [{$databaseName}] reset successfully");
    })->descriptions('Clear all tables for given database in MySQL/MariaDB');

    /**
     * Import database in MySQL.
     *
     * @throws Exception
     */
    $app->command('db:import [database_name] [dump_file]', function (Input $input, $output, $databaseName, $dumpFile) {
        $helper = $this->getHelperSet()->get('question');
        info('Importing database...');
        if (!$databaseName) {
            throw new Exception('Please provide database name');
        }
        if (!$dumpFile) {
            throw new Exception('Please provide a dump file');
        }
        if (!file_exists($dumpFile)) {
            throw new Exception("Unable to locate [$dumpFile]");
        }
        $isExistsDatabase = false;
        // check if database already exists.
        if (Mysql::isDatabaseExists($databaseName)) {
            $question = new ConfirmationQuestion(
                'Database already exists are you sure you want to continue? [y/N] ',
                false
            );
            if (!$helper->ask($input, $output, $question)) {
                warning('Aborted');

                return;
            }
            $isExistsDatabase = true;
        }

        Mysql::importDatabase($dumpFile, $databaseName, $isExistsDatabase);
    })->descriptions('Import dump file for selected database in MySQL/MariaDB');

    /**
     * Export database in MySQL.
     */
    $app->command('db:export [database_name] [--sql]', function (Input $input, $databaseName) {
        info('Exporting database...');
        $defaults = $input->getOptions();
        $data = Mysql::exportDatabase($databaseName, $defaults['sql']);
        info("Database [{$data['database']}] exported into file {$data['filename']}");
    })->descriptions('Export selected MySQL/MariaDB database');

    /**
     * Configure valet database user for MySQL/MariaDB.
     */
    $app->command('db:configure [--force]', function ($force) {
        Mysql::configure($force);
    })->descriptions('Configure valet database user for MySQL/MariaDB');

    /**
     * Visual Studio Code IDE Helper Command.
     */
    $app->command('code [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::VS_CODE);
    })->descriptions('Open project in Visual Studio Code');

    /**
     * PHPStorm IDE Helper Command.
     */
    $app->command('ps [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::PHP_STORM);
    })->descriptions('Open project in PHPStorm');

    /**
     * Atom IDE Helper Command.
     */
    $app->command('atom [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::ATOM);
    })->descriptions('Open project in Atom');

    /**
     * Sublime IDE Helper Command.
     */
    $app->command('subl [folder]', function ($folder) {
        $folder = $folder ?: getcwd();
        DevTools::run($folder, \Valet\DevTools::SUBLIME);
    })->descriptions('Open project in Sublime');

    /**
     * Set authentication token in Ngrok.
     */
    $app->command('ngrok-auth [authtoken]', function ($authtoken) {
        if (!$authtoken) {
            throw new Exception('Missing arguments to authenticate ngrok. Use: "valet ngrok-auth [authtoken]"');
        }
        Ngrok::setAuthToken($authtoken);
    })->descriptions('Set authentication token for ngrok');
}

/**
 * Load all Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include $extension;
}

/**
 * Run the application.
 */
try {
    $app->run();
} catch (Exception $e) {
    warning($e->getMessage());
}
