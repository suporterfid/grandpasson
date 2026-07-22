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
     * Login / reader-login / callback throttle (S9): DB counters with IP lockout.
     * 15 attempts / 5 minutes; after the limit, lock out for 15 minutes.
     */
    public static function allowLogin(PDO $pdo, string $route): bool
    {
        return self::allowDb($pdo, $route, 15, 300, 900);
    }
}
