<?php

namespace Valet;

use PDO;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Question\Question;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;
use Valet\Facades\PhpFpm as PhpFpmFacade;
use Valet\PackageManagers\Dnf;
use Valet\PackageManagers\Pacman;

class Mysql
{
    /**
     * @var string
     */
    const MYSQL_USER = 'valet';
    /**
     * @var CommandLine
     */
    public $cli;
    /**
     * @var Filesystem
     */
    public $files;
    /**
     * @var PackageManager
     */
    public $pm;
    /**
     * @var ServiceManager
     */
    public $sm;
    /**
     * @var Configuration
     */
    public $configuration;
    /**
     * @var string[]
     */
    public $systemDatabases = ['sys', 'performance_schema', 'information_schema', 'mysql'];
    /**
     * @var PDO
     */
    private $pdoConnection = false;
    /**
     * @var string
     */
    protected $currentPackage = '';

    /**
     * Create a new instance.
     *
     * @param PackageManager $pm
     * @param ServiceManager $sm
     * @param CommandLine    $cli
     * @param Filesystem     $files
     * @param Configuration  $configuration
     * @param Site           $site
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
        if ($this->pm->installed($this->pm->mysqlPackageName)) {
            $this->currentPackage = $this->pm->mysqlPackageName;
        }
        if ($this->pm->installed($this->pm->mariaDBPackageName)) {
            $this->currentPackage = $this->pm->mariaDBPackageName;
        }
    }

    /**
     * Install the service.
     */
    public function install($useMariaDB = false)
    {
        if ($this->pm instanceof Pacman || $this->pm instanceof Dnf) {
            $useMariaDB = true;
        }
        $package = $useMariaDB ? $this->pm->mariaDBPackageName : $this->pm->mysqlPackageName;
        $this->currentPackage = $package;
        $service = $this->serviceName();
        if (!$this->pm instanceof Pacman && !extension_loaded('mysql')) {
            $phpVersion = PhpFpmFacade::getCurrentVersion();
            $this->pm->ensureInstalled("php{$phpVersion}-mysql");
        }

        if ($package === $this->pm->mariaDBPackageName
            && $this->pm->installed($this->pm->mysqlPackageName)
        ) {
            warning('MySQL is already installed, please remove --mariadb flag and try again!');
            return;
        }

        if ($package === $this->pm->mysqlPackageName
            && $this->pm->installed($this->pm->mariaDBPackageName)
        ) {
            warning('MariaDB is already installed, please add --mariadb flag and try again!');
            return;
        }

        if ($this->pm->installed($package)) {
            $config = $this->configuration->read();
            if (!isset($config['mysql'])) {
                $config['mysql'] = [];
            }
            if (!isset($config['mysql']['password'])) {
                info('Looks like MySQL/MariaDB already installed to your system');
                $this->configure();
            }
        } else {
            $this->pm->installOrFail($package);
            $this->sm->enable($service);
            $this->stop();
            if ($this->pm instanceof Pacman) {
                // Configure data directory.
                $this->configureDataDirectory();
            }
            $this->restart();
            $input = new ArgvInput();
            $output = new ConsoleOutput();
            $question = new Question('Please enter new password for `'.self::MYSQL_USER.'` database user: ');
            $helper = new HelperSet([new QuestionHelper()]);
            $question->setHidden(true);
            $helper = $helper->get('question');
            $password = $helper->ask($input, $output, $question);
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
     * Print table of exists databases.
     */
    public function listDatabases(): void
    {
        table(['Database'], $this->getDatabases());
    }

    /**
     * Import Mysql database from file.
     */
    public function importDatabase(string $file, string $database, bool $isDatabaseExists): void
    {
        $database = $this->getDatabaseName($database);

        if (!$isDatabaseExists) {
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
        $this->cli->run("{$gzip}mysql -u {$credentials['user']} -p{$credentials['password']} {$database} {$sqlFile}");
    }

    /**
     * Drop Mysql database.
     */
    public function dropDatabase(string $name): bool
    {
        $name = $this->getDatabaseName($name);

        if (!$this->isDatabaseExists($name)) {
            warning("Database [$name] does not exists!");

            return false;
        }

        $dbDropped = $this->query('DROP DATABASE `'.$name.'`') ? true : false;

        if (!$dbDropped) {
            warning('Error dropping database');

            return false;
        }

        info("Database [{$name}] dropped successfully");

        return true;
    }

    /**
     * Create Mysql database.
     */
    public function createDatabase(string $name): void
    {
        if ($this->isDatabaseExists($name)) {
            warning("Database [$name] is already exists!");

            return;
        }

        try {
            $name = $this->getDatabaseName($name);
            if ($this->query('CREATE DATABASE IF NOT EXISTS `'.$name.'`')) {
                info("Database [{$name}] created successfully");
            }
        } catch (\Exception $exception) {
            warning('Error while creating database!');
        }
    }

    /**
     * Check if database already exists.
     */
    public function isDatabaseExists(string $name): bool
    {
        $name = $this->getDatabaseName($name);
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$name}'");
        $query->execute();

        return (bool) $query->rowCount();
    }

    /**
     * Export Mysql database.
     */
    public function exportDatabase(string $database, bool $exportSql = false): array
    {
        $database = $this->getDatabaseName($database);

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
     * Configure Database user for Valet.
     */
    public function configure(bool $force = false): void
    {
        $config = $this->configuration->read();
        if (!isset($config['mysql'])) {
            $config['mysql'] = [];
        }

        if (!$force && isset($config['mysql']['password'])) {
            info('Valet database user is already configured. Use --force to reconfigure database user.');

            return;
        }
        $input = new ArgvInput();
        $output = new ConsoleOutput();
        if (empty($config['mysql']['user'])) {
            $question = new Question('Please enter MySQL/MariaDB user: ');
        } else {
            $question = new Question(
                'Please enter MySQL/MariaDB user [current: '.$config['mysql']['user'].']: ',
                $config['mysql']['user']
            );
        }
        $helper = new HelperSet([new QuestionHelper()]);
        $helper = $helper->get('question');
        $user = $helper->ask($input, $output, $question);
        $question = new Question('Please enter MySQL/MariaDB password: ');
        $helper = new HelperSet([new QuestionHelper()]);
        $question->setHidden(true);
        $helper = $helper->get('question');
        $password = $helper->ask($input, $output, $question);

        $connection = $this->validateCredentials($user, $password);
        if (!$connection) {
            $question = new ConfirmationQuestion('Would you like to try again? [Y/n] ', true);
            if (!$helper->ask($input, $output, $question)) {
                warning('Valet database user is not configured!');

                return;
            } else {
                $this->configure($force);

                return;
            }
        }
        $config['mysql']['user'] = $user;
        $config['mysql']['password'] = $password;
        $this->configuration->write($config);
        info('Database user configured successfully!');
    }

    /**
     * Get database name via name or current dir.
     */
    private function getDatabaseName(string $database = ''): string
    {
        return $database ?: $this->getDirName();
    }

    /**
     * Get current dir name.
     */
    private function getDirName(): string
    {
        $gitDir = $this->cli->runAsUser('git rev-parse --show-toplevel 2>/dev/null');

        if ($gitDir) {
            return \trim(\basename($gitDir));
        }

        return \trim(\basename(\getcwd()));
    }

    /**
     * Get exists databases.
     *
     * @return array
     */
    private function getDatabases(): array
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
            warning($e->getMessage());
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
            warning('Connection failed due to `'.$e->getMessage().'`');

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
            warning('Failed to connect MySQL due to :`'.$e->getMessage().'`');
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
                output(\sprintf('%s: %s', $statusCode, $output));
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
        return $this->currentPackage === $this->pm->mariaDBPackageName;
    }

    /**
     * Set root password of Mysql.
     */
    private function createValetUser(string $password): void
    {
        $success = true;
        $query = "sudo mysql -e \"CREATE USER '".self::MYSQL_USER."'@'localhost' IDENTIFIED WITH mysql_native_password BY '".$password."';GRANT ALL PRIVILEGES ON *.* TO '".self::MYSQL_USER."'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;\"";
        if ($this->isMariaDB()) {
            $query = "sudo mysql -e \"CREATE USER '".self::MYSQL_USER."'@'localhost' IDENTIFIED BY '".$password."';GRANT ALL PRIVILEGES ON *.* TO '".self::MYSQL_USER."'@'localhost' WITH GRANT OPTION;FLUSH PRIVILEGES;\"";
        }
        $this->cli->run(
            $query,
            function ($statusCode, $error) use (&$success) {
                warning('Setting password for valet user failed due to `['.$statusCode.'] '.$error.'`');
                $success = false;
            }
        );

        if ($success !== false) {
            $config = $this->configuration->read();
            if (!isset($config['mysql'])) {
                $config['mysql'] = [];
            }
            $config['mysql']['user'] = self::MYSQL_USER;
            $config['mysql']['password'] = $password;
            $this->configuration->write($config);
        }
    }

    /**
     * Returns the stored password from the config. If not configured returns the default root password.
     */
    private function getCredentials(): array
    {
        $config = $this->configuration->read();
        if (!isset($config['mysql']['password']) && $config['mysql']['password'] !== null) {
            warning('Valet database user is not configured!');
            exit;
        }

        // For previously installed user.
        if (empty($config['mysql']['user'])) {
            $config['mysql']['user'] = 'root';
        }

        return ['user' => $config['mysql']['user'], 'password' => $config['mysql']['password']];
    }
}
