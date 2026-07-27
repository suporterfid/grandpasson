<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

/**
 * Clamp access-token TTL to configured default / hard max (S4).
 */
final class AccessTokenTtl
{
    public const DEFAULT_SECONDS = 900;
    public const MAX_SECONDS = 3600;

    /**
     * @param array{access_ttl_seconds?: int, access_ttl_max_seconds?: int} $tokenConfig
     */
    public static function resolve(array $tokenConfig, ?int $requestedSeconds = null): int
    {
        $default = (int) ($tokenConfig['access_ttl_seconds'] ?? self::DEFAULT_SECONDS);
        $max = (int) ($tokenConfig['access_ttl_max_seconds'] ?? self::MAX_SECONDS);

        if ($default <= 0) {
            $default = self::DEFAULT_SECONDS;
        }
        if ($max <= 0) {
            $max = self::MAX_SECONDS;
        }
        if ($default > $max) {
            $default = $max;
        }

        $ttl = $requestedSeconds === null || $requestedSeconds <= 0 ? $default : $requestedSeconds;

        return min($ttl, $max);
    }
}
