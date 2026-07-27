<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Cleanup;

use PDO;

/**
 * Prune enriched audit_log (and optionally legacy audit_events) past retention.
 */
final class AuditLogCleanup
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array{audit_log: int, audit_events: int}
     */
    public function run(int $retentionDays, ?int $nowUnix = null): array
    {
        if ($retentionDays < 1) {
            throw new \InvalidArgumentException('retentionDays must be >= 1');
        }
        $nowUnix ??= time();
        $cutoff = gmdate('Y-m-d H:i:s', $nowUnix - ($retentionDays * 86400));

        $log = $this->pdo->prepare('DELETE FROM audit_log WHERE created_at < :cutoff');
        $log->execute(['cutoff' => $cutoff]);

        $events = $this->pdo->prepare('DELETE FROM audit_events WHERE created_at < :cutoff');
        $events->execute(['cutoff' => $cutoff]);

        return [
            'audit_log' => $log->rowCount(),
            'audit_events' => $events->rowCount(),
        ];
    }
}
