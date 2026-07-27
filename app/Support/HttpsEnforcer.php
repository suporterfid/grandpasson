<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

/**
 * S7: enforce HTTPS for the broker when configured (prod default / FORCE_HTTPS).
 */
final class HttpsEnforcer
{
    /**
     * @param array{force_https?: bool, broker?: array{base_url?: string}} $config
     * @param array<string, mixed> $server Typically $_SERVER
     * @return array{enforced: bool, is_https: bool, redirect_url: ?string}
     */
    public static function evaluate(array $config, array $server = []): array
    {
        $enforced = (bool) ($config['force_https'] ?? false);
        $isHttps = self::isHttpsRequest($server);
        if (!$enforced || $isHttps) {
            return ['enforced' => $enforced, 'is_https' => $isHttps, 'redirect_url' => null];
        }

        return [
            'enforced' => true,
            'is_https' => false,
            'redirect_url' => self::httpsRedirectUrl($config, $server),
        ];
    }

    /**
     * Redirect to HTTPS when enforcement is on and the request is cleartext.
     * Returns true when a redirect was issued (caller should stop).
     *
     * @param array{force_https?: bool, broker?: array{base_url?: string}} $config
     * @param array<string, mixed> $server
     */
    public static function enforceOrContinue(array $config, array $server = []): bool
    {
        $result = self::evaluate($config, $server !== [] ? $server : $_SERVER);
        if ($result['redirect_url'] === null) {
            return false;
        }

        header('Location: ' . $result['redirect_url'], true, 301);
        header('Content-Type: text/plain; charset=utf-8');
        http_response_code(301);
        echo 'HTTPS required';

        return true;
    }

    /** @param array<string, mixed> $server */
    public static function isHttpsRequest(array $server): bool
    {
        $https = $server['HTTPS'] ?? '';
        if (is_string($https) && $https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        $port = $server['SERVER_PORT'] ?? null;
        if ($port !== null && (int) $port === 443) {
            return true;
        }

        $fwd = $server['HTTP_X_FORWARDED_PROTO'] ?? '';
        if (is_string($fwd) && strtolower(trim(explode(',', $fwd)[0])) === 'https') {
            return true;
        }

        $fwdSsl = $server['HTTP_X_FORWARDED_SSL'] ?? '';
        if (is_string($fwdSsl) && strtolower($fwdSsl) === 'on') {
            return true;
        }

        return false;
    }

    /**
     * @param array{broker?: array{base_url?: string}} $config
     * @param array<string, mixed> $server
     */
    private static function httpsRedirectUrl(array $config, array $server): string
    {
        $base = rtrim((string) ($config['broker']['base_url'] ?? ''), '/');
        if ($base !== '' && str_starts_with(strtolower($base), 'https://')) {
            $path = (string) ($server['REQUEST_URI'] ?? '/');
            if ($path === '' || !str_starts_with($path, '/')) {
                $path = '/' . ltrim($path, '/');
            }

            return $base . $path;
        }

        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');
        $uri = (string) ($server['REQUEST_URI'] ?? '/');

        return 'https://' . $host . $uri;
    }
}
