<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\AccessToken;
use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Auth\OpaqueToken;
use PDO;

final class AccessTokenRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Mint and persist an opaque access token. Returns plaintext once.
     *
     * @return array{token: string, record: AccessToken, expires_in: int}
     */
    public function issue(
        string $clientId,
        string $scope,
        ?string $aud,
        int $ttlSeconds,
        ?string $subjectUserId = null,
        ?string $tenantId = null,
    ): array {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds must be positive');
        }

        $plaintext = OpaqueToken::mint();
        $id = Uuid::v4();
        $now = time();
        $createdAt = gmdate('Y-m-d H:i:s', $now);
        $expiresAt = gmdate('Y-m-d H:i:s', $now + $ttlSeconds);

        $stmt = $this->pdo->prepare(
            'INSERT INTO access_tokens
             (id, token_hash, client_id, subject_user_id, scope, aud, tenant_id, expires_at, revoked_at, created_at, last_used_at)
             VALUES
             (:id, :hash, :client_id, :subject, :scope, :aud, :tenant_id, :expires_at, NULL, :created_at, NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'hash' => OpaqueToken::hash($plaintext),
            'client_id' => $clientId,
            'subject' => $subjectUserId,
            'scope' => $scope,
            'aud' => $aud,
            'tenant_id' => $tenantId,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
        ]);

        $record = new AccessToken(
            $id,
            OpaqueToken::hash($plaintext),
            $clientId,
            $subjectUserId,
            $scope,
            $aud,
            $tenantId,
            $expiresAt,
            null,
            $createdAt,
            null,
        );

        return [
            'token' => $plaintext,
            'record' => $record,
            'expires_in' => $ttlSeconds,
        ];
    }

    public function findByToken(string $plaintext): ?AccessToken
    {
        $stmt = $this->pdo->prepare('SELECT * FROM access_tokens WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => OpaqueToken::hash($plaintext)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function touchLastUsed(string $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE access_tokens SET last_used_at = :now WHERE id = :id'
        );
        $stmt->execute([
            'now' => gmdate('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): AccessToken
    {
        return new AccessToken(
            (string) $row['id'],
            (string) $row['token_hash'],
            (string) $row['client_id'],
            $row['subject_user_id'] !== null ? (string) $row['subject_user_id'] : null,
            (string) $row['scope'],
            $row['aud'] !== null ? (string) $row['aud'] : null,
            $row['tenant_id'] !== null ? (string) $row['tenant_id'] : null,
            (string) $row['expires_at'],
            $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            (string) $row['created_at'],
            $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
        );
    }
}
