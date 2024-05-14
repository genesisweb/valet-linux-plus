<?php

namespace Valet;

use ConsoleComponents\Writer;
use Illuminate\Support\Collection;
use PDO;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\PackageManagers\Dnf;
use Valet\PackageManagers\Pacman;

class Mysql
{
    private const DATABASE_USER = 'valet';
    public CommandLine $cli;
    public Filesystem $files;
    public PackageManager $pm;
    public ServiceManager $sm;
    public Configuration $configuration;
    /**
     * @var array|string[]
     */
    public array $systemDatabases = ['sys', 'performance_schema', 'information_schema', 'mysql'];
    private ?PDO $pdoConnection = null;
    private ?string $currentPackage = null;

    /**
     * Create a new instance.
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     * @param Filesystem     $files
     * @param Configuration  $configuration
     */
    public function __construct(
        PackageManager $pm,
        ServiceManager $sm,
        CommandLine $cli,
        Filesystem $files,
        Configuration $configuration
    ) {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->files = $files;
        $this->configuration = $configuration;

        if ($this->pm->installed($packageName = $this->pm->packageName('mysql'))) {
            $this->currentPackage = $packageName;
        } elseif ($this->pm->installed($packageName = $this->pm->packageName('mariadb'))) {
            $this->currentPackage = $packageName;
        }
    }

    /**
     * Install the service.
     */
    public function install(bool $useMariaDB = false): void
    {
        if ($this->currentPackage === null) {
            if ($this->pm instanceof Pacman || $this->pm instanceof Dnf) {
                $useMariaDB = true;
            }
            $package = $useMariaDB ? $this->pm->packageName('mariadb') : $this->pm->packageName('mysql');
            $this->currentPackage = $package;
            if (!$this->pm instanceof Pacman && !extension_loaded('mysql')) {
                $phpVersion = PhpFpmFacade::getCurrentVersion();
                $this->pm->ensureInstalled("php{$phpVersion}-mysql");
            }
        }

        if ($this->pm->installed($this->currentPackage)) {
            /** @var array<string, string> $config */
            $config = $this->configuration->get('mysql', []);
            if (!isset($config['password'])) {
                Writer::info('Looks like MySQL/MariaDB already installed to your system');
                $this->configure();
            }
        } else {
            $this->pm->installOrFail($this->currentPackage);
            $this->sm->enable($this->serviceName());
            if ($this->pm instanceof Pacman) {
                $this->stop();
                $this->configureDataDirectory();
                $this->restart();
            }

            /** @var ?string $password */
            $password = Writer::ask(\sprintf('Please enter new password for [%s] database user', self::DATABASE_USER));
            if ($password === null) {
                $password = '';
            }
            $this->createValetUser($password);
        }
    }

    /**
     * Stop the Mysql service.
     */
    public function stop(): void
    {
        $this->sm->stop($this->serviceName());
    }

    /**
     * Restart the Mysql service.
     */
    public function restart(): void
    {
        $this->sm->restart($this->serviceName());
    }

    /**
     * Prepare Mysql for uninstall.
     */
    public function uninstall(): void
    {
        $this->stop();
    }

    /**
     * Configure Database user for Valet.
     */
    public function configure(bool $force = false): void
    {
        /** @var array<string, string> $config */
        $config = $this->configuration->get('mysql', []);

        if (!$force && isset($config['password'])) {
            Writer::info('Valet database user is already configured. Use --force to reconfigure database user.');
            return;
        }

        $defaultUser = null;
        if (!empty($config['user'])) {
            $defaultUser = $config['user'];
        }
        /** @var string $user */
        $user = Writer::ask('Please enter MySQL/MariaDB user:', $defaultUser);

        /** @var string $password */
        $password = Writer::ask('Please enter MySQL/MariaDB password:');

        $connection = $this->validateCredentials($user, $password);
        if (!$connection) {
            $confirm = Writer::confirm('Would you like to try again?', true);
            if (!$confirm) {
                Writer::warn('Valet database user is not configured');
                return;
            } else {
                $this->configure($force);
                return;
            }
        }
        $config['user'] = $user;
        $config['password'] = $password;
        $this->configuration->set('mysql', $config);
        Writer::info('Database user configured successfully');
    }

    /**
     * Create Mysql database.
     */
    public function createDatabase(string $name): bool
    {
        if ($this->isDatabaseExists($name)) {
            Writer::warn("Database [$name] is already exists!");
            return false;
        }

        $isCreated = (bool)$this->query('CREATE DATABASE IF NOT EXISTS `'.$name.'`');
        if (!$isCreated) {
            Writer::warn('Error creating database');
            return false;
        }

        return true;
    }

    /**
     * Drop Mysql database.
     */
    public function dropDatabase(string $name): bool
    {
        if (!$this->isDatabaseExists($name)) {
            Writer::warn("Database [$name] does not exists!");
            return false;
        }

        $dbDropped = (bool)$this->query('DROP DATABASE `'.$name.'`');

        if (!$dbDropped) {
            Writer::warn('Error dropping database');
            return false;
        }

        return true;
    }

    /**
     * Export Mysql database.
     */
    public function exportDatabase(string $database, bool $exportSql = false): array
    {
        $filename = $database.'-'.\date('Y-m-d-H-i-s', \time());

        if ($exportSql) {
            $filename = $filename.'.sql';
        } else {
            $filename = $filename.'.sql.gz';
        }

        $credentials = $this->getCredentials();
        $command = "mysqldump -u {$credentials['user']} -p{$credentials['password']} ".escapeshellarg($database).' ';
        if ($exportSql) {
            $command .= ' > '.escapeshellarg($filename);
        } else {
            $command .= ' | gzip > '.escapeshellarg($filename);
        }
        $this->cli->run($command);

        return [
            'database' => $database,
            'filename' => $filename,
        ];
    }

    /**
     * Import Mysql database from file.
     */
    public function importDatabase(string $file, string $database): void
    {
        $isExistsDatabase = false;
        // check if database already exists.
        if ($this->isDatabaseExists($database)) {
            $confirm = Writer::confirm('Database already exists, are you sure you want to continue?');
            if (!$confirm) {
                Writer::warn('Aborted');
                return;
            }
            $isExistsDatabase = true;
        }

        if (!$isExistsDatabase) {
            $this->createDatabase($database);
        }
        $gzip = '';
        $sqlFile = '';
        if (\stristr($file, '.gz')) {
            $file = escapeshellarg($file);
            $gzip = "zcat {$file} | ";
        } else {
            $file = escapeshellarg($file);
            $sqlFile = " < {$file}";
        }
        $database = escapeshellarg($database);
        $credentials = $this->getCredentials();
        $this->cli->run(
            \sprintf(
                '%smysql -u %s -p%s %s %s',
                $gzip,
                $credentials['user'],
                $credentials['password'],
                $database,
                $sqlFile
            )
        );
    }

    /**
     * Get a list of databases.
     *
     * @return array
     */
    public function getDatabases(): array
    {
        $result = $this->query('SHOW DATABASES');

        if (!$result) {
            return ['Failed to get databases'];
        }

        return collect($result->fetchAll(PDO::FETCH_ASSOC))
            ->reject(function ($row) {
                return \in_array($row['Database'], $this->getSystemDatabases());
            })->map(function ($row) {
                return [$row['Database']];
            })->toArray();
    }

    /**
     * Check if database already exists.
     */
    private function isDatabaseExists(string $name): bool
    {
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '$name'");
        $query->execute();

        return (bool) $query->rowCount();
    }

    /**
     * Run Mysql query.
     *
     * @return bool|\PDOStatement|void
     */
    private function query(string $query)
    {
        $link = $this->getConnection();

        try {
            return $link->query($query);
        } catch (\PDOException $e) {
            Writer::warn($e->getMessage());
        }
    }

    /**
     * Validate Username & Password.
     */
    private function validateCredentials(string $username, string $password): bool
    {
        try {
            // Create connection
            $connection = new PDO(
                'mysql:host=localhost',
                $username,
                $password
            );
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return true;
        } catch (\PDOException $e) {
            Writer::error('Invalid database credentials');

            return false;
        }
    }

    /**
     * Return Mysql connection.
     */
    private function getConnection(): PDO
    {
        // if connection already exists return it early.
        if ($this->pdoConnection) {
            return $this->pdoConnection;
        }

        try {
            // Create connection
            $credentials = $this->getCredentials();
            $this->pdoConnection = new PDO(
                'mysql:host=localhost',
                $credentials['user'],
                $credentials['password']
            );
            $this->pdoConnection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->pdoConnection;
        } catch (\PDOException $e) {
            Writer::warn('Failed to connect MySQL due to :`'.$e->getMessage().'`');
            exit;
        }
    }

    /**
     * Get default databases of mysql.
     */
    private function getSystemDatabases(): array
    {
        return $this->systemDatabases;
    }

    /**
     * Configure data directory
     */
    private function configureDataDirectory(): void
    {
        $this->cli->run(
            'sudo mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql',
            function ($statusCode, $output) {
                Writer::error(\sprintf('%s: %s', $statusCode, $output));
            }
        );
        $this->restart();
    }

    private function serviceName(): string
    {
        if ($this->isMariaDB()) {
            return 'mariadb';
        }

        return 'mysql';
    }

    private function isMariaDB(): bool
    {
        return $this->currentPackage === $this->pm->packageName('mariadb');
    }

    /**
     * Set root password of Mysql.
     */
    private function createValetUser(string $password): void
    {
        $success = true;
        $query = "sudo mysql -e \"CREATE USER '".self::DATABASE_USER."'@'localhost' IDENTIFIED WITH mysql_native_password BY '".$password."';GRANT ALL PRIVILEGES ON *.* TO '".self::DATABASE_USER."'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;\"";
        if ($this->isMariaDB()) {
            $query = "sudo mysql -e \"CREATE USER '".self::DATABASE_USER."'@'localhost' IDENTIFIED BY '".$password."';GRANT ALL PRIVILEGES ON *.* TO '".self::DATABASE_USER."'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;\"";
        }
        $this->cli->run(
            $query,
            function ($statusCode, $error) use (&$success) {
                Writer::warn('Setting password for valet user failed due to `['.$statusCode.'] '.$error.'`');
                $success = false;
            }
        );

        if ($success !== false) {
            /** @var array<string, string> $config */
            $config = $this->configuration->get('mysql', []);

            $config['user'] = self::DATABASE_USER;
            $config['password'] = $password;
            $this->configuration->set('mysql', $config);
        }
    }

    /**
     * Returns the stored password from the config. If not configured returns the default root password.
     * @return array{user: string, password: string}
     */
    private function getCredentials(): array
    {
        /** @var array<string, string> $config */
        $config = $this->configuration->get('mysql', []);
        if (!isset($config['password']) && $config['password'] !== null) {
            Writer::warn('Valet database user is not configured!');
            exit;
        }

        // For previously installed user.
        if (empty($config['user'])) {
            $config['user'] = 'root';
        }

        return ['user' => $config['user'], 'password' => $config['password']];
    }
}
