<?php

namespace App\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

final class DatabaseConnectionFactory
{
    /** @param string $dsn e.g. postgresql://user:pass@host:5432/db?serverVersion=13&charset=utf8 */
    public static function create(string $dsn): Connection
    {
        $params = (new DsnParser(['postgresql' => 'pdo_pgsql', 'pgsql' => 'pdo_pgsql']))->parse($dsn);

        return DriverManager::getConnection($params);
    }
}
