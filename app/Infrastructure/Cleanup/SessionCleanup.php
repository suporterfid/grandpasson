<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Cleanup;

use PDO;

final class SessionCleanup
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return int Number of deleted rows */
    public function run(?int $now = null): int
    {
        $now ??= time();
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < :now');
        $stmt->execute(['now' => $now]);

        return $stmt->rowCount();
    }
}
