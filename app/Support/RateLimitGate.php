<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

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

    public static function allow(string $route): bool
    {
        $key = $route . '|' . Http::clientIp();

        return self::forAuthEndpoints()->attempt($key);
    }
}
