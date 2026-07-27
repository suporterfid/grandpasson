<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

use PDO;

final class RateLimitGate
{
    private static ?RateLimiter $limiter = null;

    public static function forAuthEndpoints(): RateLimiter
    {
        if (self::$limiter === null) {
            $dir = sys_get_temp_dir() . '/grandpasson-rate';
            // 60 attempts / 60s per IP+route — basic abuse brake, not a WAF.
            self::$limiter = new RateLimiter($dir, 60, 60);
        }

        return self::$limiter;
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$limiter = null;
    }

    /** File-backed throttle (login, exchange, etc.). */
    public static function allow(string $route): bool
    {
        $key = $route . '|' . Http::clientIp();

        return self::forAuthEndpoints()->attempt($key);
    }

    /**
     * DB-backed throttle for oauth token/introspect (R13 / S9).
     * Falls back to file limiter if the DB path fails (fail-open toward availability).
     */
    public static function allowDb(
        PDO $pdo,
        string $route,
        int $maxAttempts = 60,
        int $windowSeconds = 60,
        int $lockoutSeconds = 0,
    ): bool {
        $key = $route . '|' . Http::clientIp();
        try {
            return (new DbRateLimiter($pdo, $maxAttempts, $windowSeconds, $lockoutSeconds))->attempt($key);
        } catch (\Throwable) {
            return self::forAuthEndpoints()->attempt($key);
        }
    }

    /**
     * DB-backed throttle for oauth/machine-token endpoints, using §11 config knobs
     * (RATE_LIMIT_OAUTH_MAX / RATE_LIMIT_OAUTH_WINDOW_SECONDS) with the prior
     * hardcoded values (60 / 60) as defaults.
     *
     * @param array<string, mixed> $config
     */
    public static function allowOauth(PDO $pdo, string $route, array $config = []): bool
    {
        $rateLimit = $config['rate_limit'] ?? [];

        return self::allowDb(
            $pdo,
            $route,
            (int) ($rateLimit['oauth_max'] ?? 60),
            (int) ($rateLimit['oauth_window_seconds'] ?? 60),
        );
    }

    /**
     * Login / reader-login / callback throttle (S9): DB counters with IP lockout,
     * configurable via §11 knobs (RATE_LIMIT_LOGIN_MAX / _WINDOW_SECONDS / _LOCKOUT_SECONDS)
     * with the prior hardcoded values (15 attempts / 300s window / 900s lockout) as defaults.
     *
     * @param array<string, mixed> $config
     */
    public static function allowLogin(PDO $pdo, string $route, array $config = []): bool
    {
        $rateLimit = $config['rate_limit'] ?? [];

        return self::allowDb(
            $pdo,
            $route,
            (int) ($rateLimit['login_max'] ?? 15),
            (int) ($rateLimit['login_window_seconds'] ?? 300),
            (int) ($rateLimit['login_lockout_seconds'] ?? 900),
        );
    }
}
