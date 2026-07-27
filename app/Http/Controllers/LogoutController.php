<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Session\MysqlSessionHandler;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Http;

final class LogoutController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function handle(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        $audit = new AuditLogger($pdo);
        $userId = isset($_SESSION['user_id']) ? (string) $_SESSION['user_id'] : null;

        $csrf = (string) ($_POST['csrf'] ?? '');
        if (!Csrf::validate($csrf)) {
            $audit->log('logout.failure', $userId, null, Http::clientIp());
            Http::json(403, ['error' => 'invalid_csrf']);

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
                $ttl = max(60, (int) $config['session']['ttl_minutes'] * 60);
                $handler = new MysqlSessionHandler($pdo, $ttl);
                $handler->destroy($sessionId);
            } catch (\Throwable) {
                // Best-effort row delete; cookie already cleared.
            }
        }

        $audit->log('logout.success', $userId, null, Http::clientIp());
        Http::json(200, ['ok' => true]);
    }
}
