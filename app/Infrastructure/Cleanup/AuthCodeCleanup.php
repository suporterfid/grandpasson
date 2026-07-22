<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Cleanup;

use PDO;

final class AuthCodeCleanup
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Deletes expired or already-consumed auth codes.
     *
     * @return int Number of deleted rows
     */
    public function run(?int $now = null): int
    {
        $now ??= time();
        $stmt = $this->pdo->prepare(
            'DELETE FROM auth_codes WHERE consumed = 1 OR expires_at < :now'
        );
        $stmt->execute(['now' => $now]);

        return $stmt->rowCount();
    }
}
