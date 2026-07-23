<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

/**
 * HTTP auth gate for cron entrypoints invoked over the web on hosts that can't
 * run CLI cron (mirrors cron/migrate.php's MIGRATE_TOKEN pattern). CLI
 * invocation is never gated by this — callers check PHP_SAPI themselves.
 */
final class CronHttpGate
{
    public static function authorized(string $configuredToken, ?string $providedToken): bool
    {
        if ($configuredToken === '') {
            return false;
        }

        return hash_equals($configuredToken, (string) $providedToken);
    }
}
