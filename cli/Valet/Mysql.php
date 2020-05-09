<?php

namespace Valet;

use PDO;
use Symfony\Component\Console\Helper\HelperSet;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\Question;
use Valet\Contracts\PackageManager;
use Valet\Contracts\ServiceManager;

class Mysql
{
    const MYSQL_ROOT_PASSWORD = 'root';

    public $cli;
    public $files;
    public $configuration;
    public $site;
    public $systemDatabase = ['sys', 'performance_schema', 'information_schema', 'mysql'];
    /**
     * @var \PDO
     */
    protected $link = false;

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
    }

    /**
     * Install the service.
     */
    public function install()
    {
        if ($this->pm->installed('mysql-server')) {
            if (!extension_loaded('pdo')) {
                $phpVersion = \PhpFpm::getVersion();
                $this->pm->ensureInstalled("php{$phpVersion}-mysql");
            }
            beginning:
            $input = new ArgvInput();
            $output = new ConsoleOutput();
            $question = new Question('Looks like MySQL already installed to your system, please enter MySQL [root] user password to connect MySQL with us:');
            $helper = new HelperSet([new QuestionHelper()]);
            $question->setHidden(true);
            $helper = $helper->get('question');
            $rootPassword = $helper->ask($input, $output, $question);
            $connection = $this->getConnection($rootPassword ? $rootPassword : '');
            if (!$connection) {
                goto beginning;
            }
            $config = $this->configuration->read();
            if (!isset($config['mysql'])) {
                $config['mysql'] = [];
            }
            $config['mysql']['password'] = $rootPassword;
            $this->configuration->write($config);
        } else {
            if (!extension_loaded('pdo')) {
                $phpVersion = \PhpFpm::getVersion();
                $this->pm->ensureInstalled("php{$phpVersion}-mysql");
            }
            $this->pm->installOrFail('mysql-server');
            $this->sm->enable('mysql');
            $this->stop();
            $this->restart();
            $this->setRootPassword();
        }
    }

    /**
     * Stop the Mysql service.
     */
    public function stop()
    {
        $this->sm->stop('mysql');
    }

    /**
     * Restart the Mysql service.
     */
    public function restart()
    {
        $this->sm->restart('mysql');
    }

    /**
     * Prepare Mysql for uninstall.
     */
    public function uninstall()
    {
        $this->stop();
    }

    /**
     * Set root password of Mysql.
     *
     * @param string $oldPwd
     * @param string $newPwd
     */
    public function setRootPassword($oldPwd = '', $newPwd = self::MYSQL_ROOT_PASSWORD)
    {
        $success = true;
        $this->cli->runAsUser(
            "mysqladmin -u root --password='".$oldPwd."' password ".$newPwd,
            function () use (&$success) {
                warning('Setting password for root user failed.');
                $success = false;
            }
        );

        if ($success !== false) {
            $config = $this->configuration->read();
            if (!isset($config['mysql'])) {
                $config['mysql'] = [];
            }
            $config['mysql']['password'] = $newPwd;
            $this->configuration->write($config);
        }
    }

    /**
     * Returns the stored password from the config. If not configured returns the default root password.
     */
    private function getRootPassword()
    {
        $config = $this->configuration->read();
        if (isset($config['mysql']) && isset($config['mysql']['password'])) {
            return $config['mysql']['password'];
        }

        return self::MYSQL_ROOT_PASSWORD;
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
     * @return array|bool
     */
    protected function getDatabases()
    {
        $result = $this->query('SHOW DATABASES');

        if (!$result) {
            return false;
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
     * Return Mysql connection.
     *
     * @param $rootPassword bool|String
     *
     * @return bool|PDO
     */
    protected function getConnection($rootPassword = null)
    {
        // if connection already exists return it early.
        if ($this->link) {
            return $this->link;
        }

        try {
            // Create connection
            $this->link = new PDO(
                'mysql:host=localhost',
                'root',
                ($rootPassword !== null ? $rootPassword : $this->getRootPassword())
            );
            $this->link->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            return $this->link;
        } catch (\PDOException $e) {
            warning('Failed to connect to MySQL');

            return false;
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
    public function importDatabase($file, $database, $isDatabaseExists)
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
        $this->cli->run("{$gzip}mysql -u root -p{$this->getRootPassword()} {$database} {$sqlFile}");
    }

    /**
     * Get database name via name or current dir.
     *
     * @param $database
     *
     * @return string
     */
    protected function getDatabaseName($database = '')
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
    public function dropDatabase($name)
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
    public function createDatabase($name)
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
    public function isDatabaseExists($name)
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
     * @param $exportSql
     *
     * @return array
     */
    public function exportDatabase($database, $exportSql = false)
    {
        $database = $this->getDatabaseName($database);

        $filename = $database.'-'.\date('Y-m-d-H-i-s', \time());

        if ($exportSql) {
            $filename = $filename.'.sql';
        } else {
            $filename = $filename.'.sql.gz';
        }

        $command = "mysqldump -u root -p{$this->getRootPassword()} ".escapeshellarg($database).' ';
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
