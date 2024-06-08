<?php

namespace Valet\Facades;

/**
 * Class Mysql.
 *
 * @method static void        install()
 * @method static void        stop()
 * @method static void        restart()
 * @method static void        uninstall()
 * @method static void        configure(bool $force = false)
 * @method static bool        createDatabase(string $name)
 * @method static bool        dropDatabase(string $name)
 * @method static array       exportDatabase(string $database, bool $exportSql = false)
 * @method static void        importDatabase(string $file, string $database)
 * @method static array       getDatabases()
 */
class Mysql extends Facade
{
}
