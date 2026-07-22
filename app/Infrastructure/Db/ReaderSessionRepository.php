<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\Uuid;
use PDO;

/**
 * Opaque reader sessions (R14) — separate from editor AUTHSESSID cookie/session.
 */
final class ReaderSessionRepository
{
    public const COOKIE_PREFIX = 'gprd_';
    public const SCOPE_PUBLISH_READ = 'publish:read';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param list<string> $scopes
     * @return array{token: string, id: string, expires_at: string}
     */
    public function issue(string $userId, string $siteId, array $scopes, int $ttlSeconds): array
    {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds must be positive');
        }
        $raw = self::COOKIE_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = Uuid::v4();
        $now = time();
        $createdAt = gmdate('Y-m-d H:i:s', $now);
        $expiresAt = gmdate('Y-m-d H:i:s', $now + $ttlSeconds);
        $stmt = $this->pdo->prepare(
            'INSERT INTO reader_sessions (id, token_hash, user_id, site_id, scopes, expires_at, created_at)
             VALUES (:id, :hash, :user_id, :site_id, :scopes, :expires_at, :created_at)'
        );
        $stmt->execute([
            'id' => $id,
            'hash' => hash('sha256', $raw),
            'user_id' => $userId,
            'site_id' => $siteId,
            'scopes' => implode(' ', $scopes),
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
        ]);

        return ['token' => $raw, 'id' => $id, 'expires_at' => $expiresAt];
    }

    /**
     * @return array{id: string, user_id: string, site_id: string, scopes: list<string>, expires_at: string}|null
     */
    public function findActiveByToken(string $plaintext): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, user_id, site_id, scopes, expires_at
             FROM reader_sessions
             WHERE token_hash = :hash
               AND expires_at > :now
             LIMIT 1'
        );
        $stmt->execute([
            'hash' => hash('sha256', $plaintext),
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $scopes = preg_split('/\s+/', (string) $row['scopes'], -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return [
            'id' => (string) $row['id'],
            'user_id' => (string) $row['user_id'],
            'site_id' => (string) $row['site_id'],
            'scopes' => array_values($scopes),
            'expires_at' => (string) $row['expires_at'],
        ];
    }

    public function revokeByToken(string $plaintext): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM reader_sessions WHERE token_hash = :hash');
        $stmt->execute(['hash' => hash('sha256', $plaintext)]);

        return $stmt->rowCount();
    }
}
