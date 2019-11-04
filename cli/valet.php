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

use Silly\Application;
use Illuminate\Container\Container;
use Symfony\Component\Console\Question\ConfirmationQuestion;

/**
 * Create the application.
 */
Container::setInstance(new Container);

$version = 'v1.0.0';

$app = new Application('Valet+', $version);

/**
 * Detect environment
 */
Valet::environmentSetup();

/**
 * Allow Valet to be run more conveniently by allowing the Node proxy to run password-less sudo.
 */
$app->command('install [--ignore-selinux]', function ($ignoreSELinux) {
    passthru(dirname(__FILE__).'/scripts/update.sh'); // Clean up cruft

    Requirements::setIgnoreSELinux($ignoreSELinux)->check();
    Configuration::install();
    Nginx::install();
    PhpFpm::install();
    DnsMasq::install(Configuration::read()['domain']);
    Nginx::restart();
    Valet::symlinkToUsersBin();
    Mailhog::install();
    Redis::install();
    Mysql::install();

    output(PHP_EOL.'<info>Valet installed successfully!</info>');
})->descriptions('Install the Valet services', [
    '--ignore-selinux' => 'Skip SELinux checks'
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
            return info(Configuration::read()['domain']);
        }

        DnsMasq::updateDomain(
            $oldDomain = Configuration::read()['domain'], $domain = trim($domain, '.')
        );

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
    $app->command('port [port] [--https]', function ($port = null, $https) {
        if ($port === null) {
            info('Current Nginx port (HTTP): ' . Configuration::get('port', 80));
            info('Current Nginx port (HTTPS): ' . Configuration::get('https_port', 443));
            return;
        }

        $port = trim($port);

        if ($https) {
            Configuration::updateKey('https_port', $port);
        }else{
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
     * Determine if the site is secured or not
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
     * Add the current working directory to the paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        Configuration::addPath($path ?: getcwd());

        info(($path === null ? "This" : "The [{$path}]") . " directory has been added to Valet's paths.");
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Remove the current working directory from the paths configuration.
     */
    $app->command('forget [path]', function ($path = null) {
        Configuration::removePath($path ?: getcwd());

        info(($path === null ? "This" : "The [{$path}]") . " directory has been removed from Valet's paths.");
    })->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Remove the current working directory to the paths configuration.
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
     * Display all of the registered symbolic links.
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
     * Display all of the registered paths.
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
        warning("It looks like you are running `cli/valet.php` directly, please use the `valet` script in the project root instead.");
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
            Redis::restart();
//            Elasticsearch::restart();
//            RabbitMq::restart();
//            Varnish::restart();
            info('Valet services have been started.');

            return;
        }
        foreach($services as $service) {
            switch($service) {
                case 'nginx': {
                    Nginx::restart();
                    break;
                }
                case 'php': {
                    PhpFpm::restart();
                    break;
                }
                case 'mailhog': {
                    Mailhog::restart();
                    break;
                }
                case 'dnsmasq': {
                    DnsMasq::restart();
                    break;
                }
                case 'mysql': {
                    Mysql::restart();
                    break;
                }
                case 'redis': {
                    Redis::restart();
                    break;
                }
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
            Redis::restart();
//            Elasticsearch::restart();
//            RabbitMq::restart();
//            Varnish::restart();
            info('Valet services have been restarted.');

            return;
        }

        foreach($services as $service) {
            switch($service) {
                case 'nginx': {
                    Nginx::restart();
                    break;
                }
                case 'php': {
                    PhpFpm::restart();
                    break;
                }
                case 'mailhog': {
                    Mailhog::restart();
                    break;
                }
                case 'dnsmasq': {
                    DnsMasq::restart();
                    break;
                }
                case 'mysql': {
                    Mysql::restart();
                    break;
                }
                case 'redis': {
                    Redis::restart();
                    break;
                }
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
            DnsMasq::stop();
            PhpFpm::stop();
            Nginx::stop();
            Mailhog::stop();
            Mysql::stop();
            Redis::stop();
//            Elasticsearch::stop();
//            RabbitMq::stop();
//            Varnish::stop();
            info('Valet services have been stopped.');

            return;
        }

        foreach($services as $service) {
            switch($service) {
                case 'nginx': {
                    Nginx::stop();
                    break;
                }
                case 'php': {
                    PhpFpm::stop();
                    break;
                }
                case 'mailhog': {
                    Mailhog::stop();
                    break;
                }
                case 'dnsmasq': {
                    DnsMasq::stop();
                    break;
                }
                case 'mysql': {
                    Mysql::stop();
                    break;
                }
                case 'redis': {
                    Redis::stop();
                    break;
                }
//                case 'elasticsearch': {
//                    Elasticsearch::stop();
//                    break;
//                }
//                case 'rabbitmq': {
//                    RabbitMq::stop();
//                    break;
//                }
//                case 'varnish': {
//                    Varnish::stop();
//                    break;
//                }
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
            passthru($script.' update');
        }
    })->descriptions('Update Valet Linux and clean up cruft');

    /**
     * Change the PHP version to the desired one.
     */
    $app->command('use [preferedversion] [--update-cli]', function ($preferedversion = null, $updateCli = null) {
        info('Changing php-fpm version...');
        PhpFpm::changeVersion($preferedversion, $updateCli);
        info('php-fpm version successfully changed! 🎉');
    })->descriptions('Set the PHP-fpm version to use, enter "default" or leave empty to use version: ' . PhpFpm::getVersion(true), [
        '--update-cli' => 'Updates CLI version as well'
    ]);

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
     * Create database
     */
    $app->command('db [run] [name] [optional] [-y|--yes]', function ($input, $output, $run, $name, $optional) {
        $helper = $this->getHelperSet()->get('question');
        $defaults = $input->getOptions();

        if($run === 'list' || $run === 'ls') {
            Mysql::listDatabases();
            return;
        }

        if($run === 'create') {
            $databaseName = Mysql::createDatabase($name);

            if(!$databaseName) {
                warning('Error creating database');
                return;
            }

            info('Database "' . $databaseName . '" created successfully');
            return;
        }

        if($run === 'drop') {
            if(!$defaults['yes']) {
                $question = new ConfirmationQuestion('Are you sure you want to delete the database? [y/N] ', FALSE);
                if (!$helper->ask($input, $output, $question)) {
                    warning('Aborted');
                    return;
                }
            }
            $databaseName = Mysql::dropDatabase($name);

            if(!$databaseName) {
                warning('Error dropping database');
                return;
            }

            info('Database "' . $databaseName . '" dropped successfully');
            return;
        }

        if($run === 'reset') {
            if(!$defaults['yes']) {
                $question = new ConfirmationQuestion('Are you sure you want to reset the database? [y/N] ', FALSE);
                if (!$helper->ask($input, $output, $question)) {
                    warning('Aborted');
                    return;
                }
            }

            $dropped = Mysql::dropDatabase($name);

            if(!$dropped) {
                warning('Error creating database');
                return;
            }

            $databaseName = Mysql::createDatabase($name);

            if(!$databaseName) {
                warning('Error creating database');
                return;
            }

            info('Database "' . $databaseName . '" reset successfully');
            return;
        }

        if($run === 'import') {
            info('Importing database...');
            if(!$name) {
                throw new Exception('Please provide a dump file');
            }

            // check if database already exists.
            if(Mysql::isDatabaseExists($optional)){
                $question = new ConfirmationQuestion('Database already exists are you sure you want to continue? [y/N] ', FALSE);
                if (!$helper->ask($input, $output, $question)) {
                    warning('Aborted');
                    return;
                }
            }

            Mysql::importDatabase($name, $optional);
            return;
        }

        if($run === 'reimport') {
            if(!$defaults['yes']) {
                $question = new ConfirmationQuestion('Are you sure you want to reimport the database? [y/N] ', FALSE);
                if (!$helper->ask($input, $output, $question)) {
                    warning('Aborted');
                    return;
                }
            }
            info('Resetting database, importing database...');
            if(!$name) {
                throw new Exception('Please provide a dump file');
            }
            Mysql::reimportDatabase($name, $optional);
            return;
        }

        if($run === 'export' || $run === 'dump') {
            info('Exporting database...');
            $data = Mysql::exportDatabase($name, $optional);
            info('Database "' . $data['database'] . '" exported into file "' . $data['filename'] . '"');
            return;
        }

        if ($run === 'pwd' || $run === 'password') {
            if (!$name || !$optional) {
                throw new Exception('Missing arguments to change root user password. Use: "valet db pwd <old-password> <new-password>"');
            }

            info('Setting password for root user...');
            Mysql::setRootPassword($name, $optional);
            return;
        }

        throw new Exception('Command not found! Available commands: list/ls, create, drop, reset, open, import, reimport, export/dump, pwd/password');
    })->descriptions('Database commands (list/ls, create, drop, reset, open, import, reimport, export/dump)');
}

/**
 * Load all of the Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include $extension;
}

/**
 * Run the application.
 */
$app->run();
