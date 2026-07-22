<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use PDO;

final class Connection
{
    private static ?PDO $pdo = null;

    /**
     * @param array{host: string, port: int, name: string, user: string, password: string} $db
     */
    public static function get(array $db): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['name']
        );

        self::$pdo = new PDO($dsn, $db['user'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }

    /** @internal for tests */
    public static function reset(): void
    {
        self::$pdo = null;
    }
}
