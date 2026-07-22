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
     * Mint and persist an opaque access token for a service client. Returns plaintext once.
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
        return $this->persistNew(
            kind: AccessToken::KIND_ACCESS,
            clientId: $clientId,
            subjectUserId: $subjectUserId,
            scope: $scope,
            aud: $aud,
            tenantId: $tenantId,
            ttlSeconds: $ttlSeconds,
            label: null,
            oauthClientId: null,
        );
    }

    /**
     * Mint a user-issued Personal Access Token (R10). Returns plaintext once.
     *
     * @return array{token: string, record: AccessToken, expires_in: int}
     */
    public function issuePat(
        string $subjectUserId,
        string $scope,
        ?string $aud,
        int $ttlSeconds,
        ?string $label = null,
        ?string $tenantId = null,
    ): array {
        if ($subjectUserId === '') {
            throw new \InvalidArgumentException('subjectUserId is required for PATs');
        }

        return $this->persistNew(
            kind: AccessToken::KIND_PAT,
            clientId: null,
            subjectUserId: $subjectUserId,
            scope: $scope,
            aud: $aud,
            tenantId: $tenantId,
            ttlSeconds: $ttlSeconds,
            label: $label,
            oauthClientId: null,
        );
    }

    /**
     * Mint an opaque access token for an RP oauth_client after authorization_code+PKCE (R11).
     *
     * @return array{token: string, record: AccessToken, expires_in: int}
     */
    public function issueForOauthUser(
        string $oauthClientId,
        string $subjectUserId,
        string $scope,
        ?string $aud,
        int $ttlSeconds,
        ?string $tenantId = null,
    ): array {
        if ($oauthClientId === '' || $subjectUserId === '') {
            throw new \InvalidArgumentException('oauthClientId and subjectUserId are required');
        }

        return $this->persistNew(
            kind: AccessToken::KIND_ACCESS,
            clientId: null,
            subjectUserId: $subjectUserId,
            scope: $scope,
            aud: $aud,
            tenantId: $tenantId,
            ttlSeconds: $ttlSeconds,
            label: null,
            oauthClientId: $oauthClientId,
        );
    }

    /**
     * @return array{token: string, record: AccessToken, expires_in: int}
     */
    private function persistNew(
        string $kind,
        ?string $clientId,
        ?string $subjectUserId,
        string $scope,
        ?string $aud,
        ?string $tenantId,
        int $ttlSeconds,
        ?string $label,
        ?string $oauthClientId = null,
    ): array {
        if ($ttlSeconds <= 0) {
            throw new \InvalidArgumentException('ttlSeconds must be positive');
        }

        $plaintext = OpaqueToken::mint();
        $id = Uuid::v4();
        $now = time();
        $createdAt = gmdate('Y-m-d H:i:s', $now);
        $expiresAt = gmdate('Y-m-d H:i:s', $now + $ttlSeconds);
        $hash = OpaqueToken::hash($plaintext);

        $stmt = $this->pdo->prepare(
            'INSERT INTO access_tokens
             (id, token_hash, kind, label, client_id, oauth_client_id, subject_user_id, scope, aud, tenant_id, expires_at, revoked_at, created_at, last_used_at)
             VALUES
             (:id, :hash, :kind, :label, :client_id, :oauth_client_id, :subject, :scope, :aud, :tenant_id, :expires_at, NULL, :created_at, NULL)'
        );
        $stmt->execute([
            'id' => $id,
            'hash' => $hash,
            'kind' => $kind,
            'label' => $label,
            'client_id' => $clientId,
            'oauth_client_id' => $oauthClientId,
            'subject' => $subjectUserId,
            'scope' => $scope,
            'aud' => $aud,
            'tenant_id' => $tenantId,
            'expires_at' => $expiresAt,
            'created_at' => $createdAt,
        ]);

        $record = new AccessToken(
            $id,
            $hash,
            $clientId,
            $subjectUserId,
            $scope,
            $aud,
            $tenantId,
            $expiresAt,
            null,
            $createdAt,
            null,
            $kind,
            $label,
            $oauthClientId,
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

    /**
     * Atomically mark last_used_at only if the token is still unrevoked and unexpired.
     * Returns false if the row is inactive (or missing).
     */
    public function touchLastUsedIfActive(string $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE access_tokens
             SET last_used_at = :touched_at
             WHERE id = :id
               AND revoked_at IS NULL
               AND expires_at > :expires_gate'
        );
        $stmt->execute([
            'touched_at' => $now,
            'id' => $id,
            'expires_gate' => $now,
        ]);

        return $stmt->rowCount() === 1;
    }

    public function touchLastUsed(string $id): void
    {
        $this->touchLastUsedIfActive($id);
    }

    public function revokeById(string $id): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE access_tokens
             SET revoked_at = :now
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'now' => gmdate('Y-m-d H:i:s'),
            'id' => $id,
        ]);

        return $stmt->rowCount();
    }

    /** Revoke by plaintext bearer token. Returns 1 if newly revoked, else 0. */
    public function revokeByToken(string $plaintext): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE access_tokens
             SET revoked_at = :now
             WHERE token_hash = :hash AND revoked_at IS NULL'
        );
        $stmt->execute([
            'now' => gmdate('Y-m-d H:i:s'),
            'hash' => OpaqueToken::hash($plaintext),
        ]);

        return $stmt->rowCount();
    }

    /** Revoke all still-active tokens for a service client. */
    public function revokeByClientId(string $clientId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE access_tokens
             SET revoked_at = :now
             WHERE client_id = :client_id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'now' => gmdate('Y-m-d H:i:s'),
            'client_id' => $clientId,
        ]);

        return $stmt->rowCount();
    }

    /** Revoke all still-active tokens for a subject user. */
    public function revokeBySubjectId(string $subjectUserId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE access_tokens
             SET revoked_at = :now
             WHERE subject_user_id = :subject AND revoked_at IS NULL'
        );
        $stmt->execute([
            'now' => gmdate('Y-m-d H:i:s'),
            'subject' => $subjectUserId,
        ]);

        return $stmt->rowCount();
    }

    /**
     * @return list<AccessToken>
     */
    public function listActive(
        ?string $clientId = null,
        ?string $subjectUserId = null,
        ?string $kind = null,
    ): array {
        $sql = 'SELECT * FROM access_tokens WHERE revoked_at IS NULL AND expires_at > :now';
        $params = ['now' => gmdate('Y-m-d H:i:s')];
        if ($clientId !== null && $clientId !== '') {
            $sql .= ' AND client_id = :client_id';
            $params['client_id'] = $clientId;
        }
        if ($subjectUserId !== null && $subjectUserId !== '') {
            $sql .= ' AND subject_user_id = :subject';
            $params['subject'] = $subjectUserId;
        }
        if ($kind !== null && $kind !== '') {
            $sql .= ' AND kind = :kind';
            $params['kind'] = $kind;
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->map($row);
        }

        return $out;
    }

    public function findById(string $id): ?AccessToken
    {
        $stmt = $this->pdo->prepare('SELECT * FROM access_tokens WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): AccessToken
    {
        return new AccessToken(
            (string) $row['id'],
            (string) $row['token_hash'],
            $row['client_id'] !== null ? (string) $row['client_id'] : null,
            $row['subject_user_id'] !== null ? (string) $row['subject_user_id'] : null,
            (string) $row['scope'],
            $row['aud'] !== null ? (string) $row['aud'] : null,
            $row['tenant_id'] !== null ? (string) $row['tenant_id'] : null,
            (string) $row['expires_at'],
            $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            (string) $row['created_at'],
            $row['last_used_at'] !== null ? (string) $row['last_used_at'] : null,
            isset($row['kind']) ? (string) $row['kind'] : AccessToken::KIND_ACCESS,
            isset($row['label']) && $row['label'] !== null ? (string) $row['label'] : null,
            isset($row['oauth_client_id']) && $row['oauth_client_id'] !== null
                ? (string) $row['oauth_client_id']
                : null,
        );
    }
}
