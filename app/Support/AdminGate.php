<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

/**
 * Shared-hosting admin auth (R12): require ADMIN_API_TOKEN (fail-closed when unset).
 */
final class AdminGate
{
    /**
     * @param array<string, mixed> $config
     */
    public static function isConfigured(array $config): bool
    {
        $token = (string) ($config['admin_api_token'] ?? '');

        return $token !== '';
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function providedToken(): string
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1) {
            return trim($m[1]);
        }
        $x = (string) ($_SERVER['HTTP_X_ADMIN_TOKEN'] ?? '');
        if ($x !== '') {
            return $x;
        }
        // Form posts only — avoid reading php://input (JSON can be consumed once).
        if (isset($_POST['admin_token']) && is_string($_POST['admin_token'])) {
            return $_POST['admin_token'];
        }

        return (string) ($_GET['admin_token'] ?? '');
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function authorize(array $config): bool
    {
        if (!self::isConfigured($config)) {
            return false;
        }
        $expected = (string) $config['admin_api_token'];
        $provided = self::providedToken();
        if ($provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
