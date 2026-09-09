<?php

namespace ElektronNet\Payments\PayServer\Db;

use ElektronNet\Payments\PayServer\Config;
use PDO;

/**
 * Thin PDO factory. `pay-api` and `pay-watcher` both construct one of these
 * from the same Config so a single connection convention is shared between
 * the request-driven process and the long-running daemon.
 */
final class Database
{
    public static function connect(Config $config): PDO
    {
        $pdo = new PDO($config->dbDsn(), $config->dbUser(), $config->dbPassword(), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return $pdo;
    }
}
