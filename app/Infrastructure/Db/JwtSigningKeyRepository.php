<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\JwtSigningKey;
use PDO;

final class JwtSigningKeyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Generate a new RS256 keypair, mark it active, and demote the previous active key to retiring.
     */
    public function rotate(int $bits = 2048): JwtSigningKey
    {
        if ($bits < 2048) {
            throw new \InvalidArgumentException('RSA key size must be >= 2048');
        }
        $keypair = openssl_pkey_new([
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($keypair === false) {
            throw new \RuntimeException('openssl_pkey_new failed');
        }
        $privatePem = '';
        if (!openssl_pkey_export($keypair, $privatePem) || $privatePem === '') {
            throw new \RuntimeException('openssl_pkey_export failed');
        }
        $details = openssl_pkey_get_details($keypair);
        if ($details === false || empty($details['key'])) {
            throw new \RuntimeException('openssl_pkey_get_details failed');
        }
        $publicPem = (string) $details['key'];
        $kid = 'jwt_' . bin2hex(random_bytes(8));
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $demote = $this->pdo->prepare(
                "UPDATE jwt_signing_keys
                 SET status = 'retiring'
                 WHERE status = 'active'"
            );
            $demote->execute();

            $insert = $this->pdo->prepare(
                'INSERT INTO jwt_signing_keys (kid, alg, public_pem, private_pem, status, created_at, retired_at)
                 VALUES (:kid, \'RS256\', :public_pem, :private_pem, \'active\', :created_at, NULL)'
            );
            $insert->execute([
                'kid' => $kid,
                'public_pem' => $publicPem,
                'private_pem' => $privatePem,
                'created_at' => $now,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return new JwtSigningKey($kid, 'RS256', $publicPem, $privatePem, JwtSigningKey::STATUS_ACTIVE, $now, null);
    }

    public function findActive(): ?JwtSigningKey
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM jwt_signing_keys WHERE status = 'active' ORDER BY created_at DESC LIMIT 1"
        );
        $row = $stmt === false ? false : $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    /**
     * @return list<JwtSigningKey>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM jwt_signing_keys ORDER BY created_at DESC');
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->map($row);
        }

        return $out;
    }

    /**
     * Keys still valid for verification (active + retiring).
     *
     * @return list<JwtSigningKey>
     */
    public function listVerifiable(): array
    {
        $stmt = $this->pdo->query(
            "SELECT * FROM jwt_signing_keys
             WHERE status IN ('active', 'retiring')
             ORDER BY created_at DESC"
        );
        $rows = $stmt === false ? [] : $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->map($row);
        }

        return $out;
    }

    public function retire(string $kid): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE jwt_signing_keys
             SET status = 'retired', retired_at = :retired_at
             WHERE kid = :kid AND status <> 'retired'"
        );
        $stmt->execute([
            'kid' => $kid,
            'retired_at' => gmdate('Y-m-d H:i:s'),
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('Unknown or already retired kid: ' . $kid);
        }
    }

    /**
     * JWKS public key set (RFC 7517) for active+retiring RS256 keys.
     *
     * @return array{keys: list<array<string, mixed>>}
     */
    public function jwks(): array
    {
        $keys = [];
        foreach ($this->listVerifiable() as $key) {
            $jwk = $this->publicPemToJwk($key->publicPem, $key->kid, $key->alg);
            if ($jwk !== null) {
                $keys[] = $jwk;
            }
        }

        return ['keys' => $keys];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function publicPemToJwk(string $publicPem, string $kid, string $alg): ?array
    {
        $res = openssl_pkey_get_public($publicPem);
        if ($res === false) {
            return null;
        }
        $details = openssl_pkey_get_details($res);
        if ($details === false || ($details['type'] ?? null) !== OPENSSL_KEYTYPE_RSA) {
            return null;
        }
        $n = $details['rsa']['n'] ?? null;
        $e = $details['rsa']['e'] ?? null;
        if (!is_string($n) || !is_string($e)) {
            return null;
        }

        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => $alg,
            'kid' => $kid,
            'n' => rtrim(strtr(base64_encode($n), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($e), '+/', '-_'), '='),
        ];
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): JwtSigningKey
    {
        return new JwtSigningKey(
            (string) $row['kid'],
            (string) $row['alg'],
            (string) $row['public_pem'],
            (string) $row['private_pem'],
            (string) $row['status'],
            (string) $row['created_at'],
            $row['retired_at'] !== null ? (string) $row['retired_at'] : null,
        );
    }
}
