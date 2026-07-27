<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use GrandpaSSOn\Infrastructure\Providers\Pkce;
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
    public function mint(
        string $userId,
        string $clientId,
        string $redirectUri,
        ?string $codeChallenge = null,
        ?string $codeChallengeMethod = null,
    ): string {
        if ($codeChallenge !== null && $codeChallenge !== '') {
            $method = strtoupper((string) ($codeChallengeMethod ?? 'S256'));
            if ($method !== 'S256') {
                throw new \InvalidArgumentException('Only S256 code_challenge_method is supported');
            }
            $codeChallengeMethod = $method;
        } else {
            $codeChallenge = null;
            $codeChallengeMethod = null;
        }

        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $raw);
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_codes
             (code_hash, user_id, client_id, redirect_uri, code_challenge, code_challenge_method, expires_at, consumed)
             VALUES
             (:hash, :user_id, :client_id, :redirect_uri, :challenge, :method, :expires_at, 0)'
        );
        $stmt->execute([
            'hash' => $hash,
            'user_id' => $userId,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'challenge' => $codeChallenge,
            'method' => $codeChallengeMethod,
            'expires_at' => time() + self::TTL_SECONDS,
        ]);

        return $raw;
    }

    /**
     * Atomically consume a code. Returns user_id on success, null on failure.
     * When the code was minted with PKCE, code_verifier is required and must match.
     */
    public function consume(
        string $rawCode,
        string $clientId,
        string $redirectUri,
        ?string $codeVerifier = null,
    ): ?string {
        $hash = hash('sha256', $rawCode);
        $now = time();

        try {
            $this->pdo->beginTransaction();
            $select = $this->pdo->prepare(
                'SELECT user_id, code_challenge, code_challenge_method
                 FROM auth_codes
                 WHERE code_hash = :hash
                   AND client_id = :client_id
                   AND redirect_uri = :redirect_uri
                   AND consumed = 0
                   AND expires_at > :now
                 FOR UPDATE'
            );
            $select->execute([
                'hash' => $hash,
                'client_id' => $clientId,
                'redirect_uri' => $redirectUri,
                'now' => $now,
            ]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->pdo->rollBack();

                return null;
            }

            $challenge = $row['code_challenge'] !== null ? (string) $row['code_challenge'] : null;
            if ($challenge !== null && $challenge !== '') {
                $method = (string) ($row['code_challenge_method'] ?? 'S256');
                if ($codeVerifier === null || $codeVerifier === ''
                    || !Pkce::verify($codeVerifier, $challenge, $method)
                ) {
                    $this->pdo->rollBack();

                    return null;
                }
            }

            $update = $this->pdo->prepare(
                'UPDATE auth_codes SET consumed = 1 WHERE code_hash = :hash AND consumed = 0'
            );
            $update->execute(['hash' => $hash]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();

                return null;
            }
            $this->pdo->commit();

            return (string) $row['user_id'];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
