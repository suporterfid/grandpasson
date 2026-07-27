<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Session;

use GrandpaSSOn\Infrastructure\Db\Connection;

final class SessionBootstrap
{
    /**
     * @param array{
     *   session: array{cookie_name: string, secure: bool, ttl_minutes: int},
     *   db: array{host: string, port: int, name: string, user: string, password: string}
     * } $config
     */
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $pdo = Connection::get($config['db']);
        $ttl = max(60, $config['session']['ttl_minutes'] * 60);
        $handler = new MysqlSessionHandler($pdo, $ttl);
        session_set_save_handler($handler, true);

        session_name($config['session']['cookie_name']);
        session_set_cookie_params([
            'lifetime' => $ttl,
            'path' => '/',
            'secure' => $config['session']['secure'],
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();
    }
}
