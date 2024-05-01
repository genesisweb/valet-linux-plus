<?php

namespace Valet\Facades;

/**
 * Class Mysql.
 *
 * @method static void        install($useMariaDB = false)
 * @method static void        stop()
 * @method static void        restart()
 * @method static void        uninstall()
 * @method static void        configure($force = false)
 * @method static void        listDatabases()
 * @method static void        importDatabase(string $file, string $database)
 * @method static bool        dropDatabase(string $name)
 * @method static bool        createDatabase(string $name)
 * @method static bool        isDatabaseExists(string $name)
 * @method static array       exportDatabase(string $database, bool $exportSql = false)
 */
class Mysql extends Facade
{
}
