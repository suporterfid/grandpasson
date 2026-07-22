<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

use PDO;
use PDOException;

/**
 * DB-backed fixed-window rate limiter (R13 / S9) — no Redis.
 * One row per key; window resets when window_started_at + windowSeconds is past.
 */
final class DbRateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $maxAttempts = 60,
        private readonly int $windowSeconds = 60,
    ) {
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be >= 1');
        }
        if ($this->windowSeconds < 1) {
            throw new \InvalidArgumentException('windowSeconds must be >= 1');
        }
    }

    /**
     * Record a hit. Returns true when allowed, false when throttled.
     */
    public function attempt(string $key, ?int $now = null): bool
    {
        $now ??= time();
        $hash = hash('sha256', $key);
        $updatedAt = gmdate('Y-m-d H:i:s', $now);

        try {
            $this->pdo->beginTransaction();

            $stmt = $this->pdo->prepare(
                'SELECT window_started_at, hit_count
                 FROM rate_limit_counters
                 WHERE counter_key = :k
                 FOR UPDATE'
            );
            $stmt->execute(['k' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                $insert = $this->pdo->prepare(
                    'INSERT INTO rate_limit_counters (counter_key, window_started_at, hit_count, updated_at)
                     VALUES (:k, :started, 1, :updated)'
                );
                $insert->execute([
                    'k' => $hash,
                    'started' => $now,
                    'updated' => $updatedAt,
                ]);
                $this->pdo->commit();

                return true;
            }

            $started = (int) $row['window_started_at'];
            $hits = (int) $row['hit_count'];
            $inWindow = ($now - $started) < $this->windowSeconds;

            if (!$inWindow) {
                $update = $this->pdo->prepare(
                    'UPDATE rate_limit_counters
                     SET window_started_at = :started, hit_count = 1, updated_at = :updated
                     WHERE counter_key = :k'
                );
                $update->execute([
                    'started' => $now,
                    'updated' => $updatedAt,
                    'k' => $hash,
                ]);
                $this->pdo->commit();

                return true;
            }

            if ($hits >= $this->maxAttempts) {
                $touch = $this->pdo->prepare(
                    'UPDATE rate_limit_counters SET updated_at = :updated WHERE counter_key = :k'
                );
                $touch->execute(['updated' => $updatedAt, 'k' => $hash]);
                $this->pdo->commit();

                return false;
            }

            $bump = $this->pdo->prepare(
                'UPDATE rate_limit_counters
                 SET hit_count = hit_count + 1, updated_at = :updated
                 WHERE counter_key = :k'
            );
            $bump->execute(['updated' => $updatedAt, 'k' => $hash]);
            $this->pdo->commit();

            return true;
        } catch (PDOException $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
