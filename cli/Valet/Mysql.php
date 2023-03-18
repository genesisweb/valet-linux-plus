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
use Valet\PackageManagers\Dnf;
use Valet\PackageManagers\Pacman;

class Mysql
{
    const MYSQL_USER = 'valet';

    public $cli;
    public $files;
    public $pm;
    public $sm;
    public $configuration;
    public $site;
    public $systemDatabase = ['sys', 'performance_schema', 'information_schema', 'mysql'];
    /**
     * @var PDO
     */
    protected $link = false;
    protected $currentPackage;

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
        Configuration $configuration,
        Site $site
    ) {
        $this->cli = $cli;
        $this->pm = $pm;
        $this->sm = $sm;
        $this->site = $site;
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
        if ($this->pm instanceof Pacman && !extension_loaded('mysql')) {
            $phpVersion = \PhpFpm::getVersion(true);
            $this->pm->ensureInstalled("php{$phpVersion}-mysql");
        }

        if ($package === $this->pm->mariaDBPackageName) {
            if ($this->pm->installed($this->pm->mysqlPackageName)) {
                warning('MySQL is already installed, please remove --mariadb flag and try again!');

                return;
            }
        }
        if ($package === $this->pm->mysqlPackageName) {
            if ($this->pm->installed($this->pm->mariaDBPackageName)) {
                warning('MariaDB is already installed, please add --mariadb flag and try again!');

                return;
            }
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
    public function stop()
    {
        $this->sm->stop($this->serviceName());
    }

    /**
     * Restart the Mysql service.
     */
    public function restart()
    {
        $this->sm->restart($this->serviceName());
    }

    /**
     * Prepare Mysql for uninstall.
     */
    public function uninstall()
    {
        $this->stop();
    }

    public function configureDataDirectory()
    {
        $this->cli->runAsUser('mariadb-install-db --user=mysql --basedir=/usr --datadir=/var/lib/mysql', function ($statusCode, $output) {
            output($output);
        });
    }

    /**
     * Configure Database user for Valet.
     *
     * @param bool $force
     *
     * @return void
     */
    public function configure(bool $force = false)
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
            $question = new Question('Please enter MySQL/MariaDB user [current: '.$config['mysql']['user'].']: ', $config['mysql']['user']);
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

    private function serviceName()
    {
        if ($this->isMariaDB()) {
            return 'mariadb';
        }

        return 'mysql';
    }

    private function isMariaDB()
    {
        return $this->currentPackage === $this->pm->mariaDBPackageName;
    }

    /**
     * Set root password of Mysql.
     *
     * @param string $password
     */
    private function createValetUser(string $password)
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
    private function getCredentials()
    {
        $config = $this->configuration->read();
        if (!isset($config['mysql']['password']) && !is_null($config['mysql']['password'])) {
            warning('Valet database user is not configured!');
            exit;
        }

        // For previously installed user.
        if (empty($config['mysql']['user'])) {
            $config['mysql']['user'] = 'root';
        }

        return ['user' => $config['mysql']['user'], 'password' => $config['mysql']['password']];
    }

    /**
     * Print table of exists databases.
     */
    public function listDatabases()
    {
        table(['Database'], $this->getDatabases());
    }

    /**
     * Get exists databases.
     *
     * @return array
     */
    protected function getDatabases()
    {
        $result = $this->query('SHOW DATABASES');

        if (!$result) {
            return ['Failed to get databases'];
        }

        return collect($result->fetchAll(PDO::FETCH_ASSOC))
            ->reject(function ($row) {
                return \in_array($row['Database'], $this->getSystemDatabase());
            })->map(function ($row) {
                return [$row['Database']];
            })->toArray();
    }

    /**
     * Run Mysql query.
     *
     * @param $query
     *
     * @return bool|\PDOException
     */
    protected function query($query)
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
     *
     * @param $username
     * @param $password
     *
     * @return bool
     */
    protected function validateCredentials($username, $password)
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
     *
     * @return bool|PDO
     */
    protected function getConnection()
    {
        // if connection already exists return it early.
        if ($this->link) {
            return $this->link;
        }

        try {
            // Create connection
            $credentials = $this->getCredentials();
            $this->link = new PDO(
                'mysql:host=localhost',
                $credentials['user'],
                $credentials['password']
            );
            $this->link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->link;
        } catch (\PDOException $e) {
            warning('Failed to connect MySQL due to :`'.$e->getMessage().'`');
            exit;
        }
    }

    /**
     * Get default databases of mysql.
     *
     * @return array
     */
    protected function getSystemDatabase()
    {
        return $this->systemDatabase;
    }

    /**
     * Import Mysql database from file.
     *
     * @param string $file
     * @param string $database
     * @param bool   $isDatabaseExists
     */
    public function importDatabase(string $file, string $database, bool $isDatabaseExists)
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
     * Get database name via name or current dir.
     *
     * @param string $database
     *
     * @return string
     */
    protected function getDatabaseName(string $database = '')
    {
        return $database ?: $this->getDirName();
    }

    /**
     * Get current dir name.
     *
     * @return string
     */
    public function getDirName()
    {
        $gitDir = $this->cli->runAsUser('git rev-parse --show-toplevel 2>/dev/null');

        if ($gitDir) {
            return \trim(\basename($gitDir));
        }

        return \trim(\basename(\getcwd()));
    }

    /**
     * Drop Mysql database.
     *
     * @param string $name
     *
     * @return bool
     */
    public function dropDatabase(string $name)
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
     *
     * @param string $name
     *
     * @return bool|string
     */
    public function createDatabase(string $name)
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
     *
     * @param string $name
     *
     * @return bool
     */
    public function isDatabaseExists(string $name)
    {
        $name = $this->getDatabaseName($name);
        $query = $this->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = '{$name}'");
        $query->execute();

        return (bool) $query->rowCount();
    }

    /**
     * Export Mysql database.
     *
     * @param $database
     * @param bool $exportSql
     *
     * @return array
     */
    public function exportDatabase($database, bool $exportSql = false)
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
}
