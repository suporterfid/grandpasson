<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use PDO;

/** Sticky active-tenant preference per user (R2). */
final class UserActiveTenantRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function getTenantId(string $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT tenant_id FROM user_active_tenant WHERE user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetchColumn();

        return $row === false ? null : (string) $row;
    }

    public function set(string $userId, string $tenantId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_active_tenant (user_id, tenant_id, updated_at)
             VALUES (:user_id, :tenant_id, :updated_at)
             ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id), updated_at = VALUES(updated_at)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function clear(string $userId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM user_active_tenant WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }
}
