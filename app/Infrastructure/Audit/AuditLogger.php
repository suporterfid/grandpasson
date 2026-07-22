<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Audit;

use PDO;

final class AuditLogger
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(string $eventType, ?string $userId = null, ?string $provider = null, ?string $ip = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_events (user_id, event_type, provider, ip_hash, created_at)
             VALUES (:user_id, :event_type, :provider, :ip_hash, :created_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'event_type' => $eventType,
            'provider' => $provider,
            'ip_hash' => $ip !== null && $ip !== '' ? hash('sha256', $ip) : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
