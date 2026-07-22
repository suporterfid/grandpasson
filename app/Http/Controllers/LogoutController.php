<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Session\MysqlSessionHandler;

final class LogoutController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function handle(array $config, array $params = []): void
    {
        $csrf = (string) ($_POST['csrf'] ?? '');
        $sessionCsrf = (string) ($_SESSION['csrf'] ?? '');
        if ($sessionCsrf === '' || $csrf === '' || !hash_equals($sessionCsrf, $csrf)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'invalid_csrf'], JSON_THROW_ON_ERROR);

            return;
        }

        $sessionId = session_id();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $paramsCookie = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $paramsCookie['path'],
                'domain' => $paramsCookie['domain'],
                'secure' => (bool) $paramsCookie['secure'],
                'httponly' => (bool) $paramsCookie['httponly'],
                'samesite' => $paramsCookie['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();

        if ($sessionId !== '') {
            try {
                $pdo = Connection::get($config['db']);
                $ttl = max(60, (int) $config['session']['ttl_minutes'] * 60);
                $handler = new MysqlSessionHandler($pdo, $ttl);
                $handler->destroy($sessionId);
            } catch (\Throwable) {
                // Best-effort row delete; cookie already cleared.
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
    }
}
