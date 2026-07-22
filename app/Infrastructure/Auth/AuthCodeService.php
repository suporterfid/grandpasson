<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use PDO;

final class AuthCodeService
{
    private const TTL_SECONDS = 60;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return string Raw code (shown once to the client redirect)
     */
    public function mint(string $userId, string $clientId, string $redirectUri): string
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $raw);
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_codes (code_hash, user_id, client_id, redirect_uri, expires_at, consumed)
             VALUES (:hash, :user_id, :client_id, :redirect_uri, :expires_at, 0)'
        );
        $stmt->execute([
            'hash' => $hash,
            'user_id' => $userId,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'expires_at' => time() + self::TTL_SECONDS,
        ]);

        return $raw;
    }

    /**
     * Atomically consume a code. Returns user_id on success, null on failure.
     */
    public function consume(string $rawCode, string $clientId, string $redirectUri): ?string
    {
        $hash = hash('sha256', $rawCode);
        $now = time();

        $stmt = $this->pdo->prepare(
            'UPDATE auth_codes
             SET consumed = 1
             WHERE code_hash = :hash
               AND client_id = :client_id
               AND redirect_uri = :redirect_uri
               AND consumed = 0
               AND expires_at > :now'
        );
        $stmt->execute([
            'hash' => $hash,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'now' => $now,
        ]);

        if ($stmt->rowCount() !== 1) {
            return null;
        }

        $select = $this->pdo->prepare('SELECT user_id FROM auth_codes WHERE code_hash = :hash LIMIT 1');
        $select->execute(['hash' => $hash]);
        $userId = $select->fetchColumn();

        return $userId === false ? null : (string) $userId;
    }
}
