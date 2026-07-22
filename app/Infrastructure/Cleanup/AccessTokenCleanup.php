<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Cleanup;

use PDO;

/**
 * Delete expired access tokens and optionally aged revoked rows.
 */
final class AccessTokenCleanup
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param int|null $nowUnix Unix timestamp for "now" (tests)
     * @param int $revokedGraceSeconds Keep recently revoked rows this long (default 7d)
     * @return int Deleted row count
     */
    public function run(?int $nowUnix = null, int $revokedGraceSeconds = 604800): int
    {
        $nowUnix ??= time();
        $now = gmdate('Y-m-d H:i:s', $nowUnix);
        $revokedCutoff = gmdate('Y-m-d H:i:s', $nowUnix - max(0, $revokedGraceSeconds));

        $stmt = $this->pdo->prepare(
            'DELETE FROM access_tokens
             WHERE expires_at < :now
                OR (revoked_at IS NOT NULL AND revoked_at < :revoked_cutoff)'
        );
        $stmt->execute([
            'now' => $now,
            'revoked_cutoff' => $revokedCutoff,
        ]);

        return $stmt->rowCount();
    }
}
