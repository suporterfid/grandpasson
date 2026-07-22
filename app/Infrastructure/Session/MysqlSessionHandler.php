<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Session;

use PDO;
use SessionHandlerInterface;

final class MysqlSessionHandler implements SessionHandlerInterface
{
    private string $lastWrittenData = '';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $ttlSeconds,
    ) {
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare(
            'SELECT data FROM sessions WHERE id = :id AND expires_at > :now LIMIT 1'
        );
        $stmt->execute([
            'id' => $id,
            'now' => time(),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $this->lastWrittenData = '';

            return '';
        }

        $data = is_string($row['data']) ? $row['data'] : (string) $row['data'];
        $this->lastWrittenData = $data;

        return $data;
    }

    public function write(string $id, string $data): bool
    {
        $now = time();
        $expires = $now + $this->ttlSeconds;

        if ($data === $this->lastWrittenData) {
            // Touch expiry only — do not rewrite the data blob (write-on-change).
            $stmt = $this->pdo->prepare(
                'INSERT INTO sessions (id, user_id, data, last_access, expires_at)
                 VALUES (:id, NULL, :data, :last_access, :expires_at)
                 ON DUPLICATE KEY UPDATE last_access = VALUES(last_access), expires_at = VALUES(expires_at)'
            );

            return $stmt->execute([
                'id' => $id,
                'data' => $data,
                'last_access' => $now,
                'expires_at' => $expires,
            ]);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO sessions (id, user_id, data, last_access, expires_at)
             VALUES (:id, NULL, :data, :last_access, :expires_at)
             ON DUPLICATE KEY UPDATE data = VALUES(data), last_access = VALUES(last_access), expires_at = VALUES(expires_at)'
        );
        $ok = $stmt->execute([
            'id' => $id,
            'data' => $data,
            'last_access' => $now,
            'expires_at' => $expires,
        ]);
        if ($ok) {
            $this->lastWrittenData = $data;
        }

        return $ok;
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $this->lastWrittenData = '';

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare('DELETE FROM sessions WHERE expires_at < :now');
        $stmt->execute(['now' => time()]);

        return $stmt->rowCount();
    }
}
