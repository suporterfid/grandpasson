<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\ServiceClient;
use GrandpaSSOn\Infrastructure\Auth\ClientSecretHasher;
use PDO;

final class ServiceClientRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByClientId(string $clientId): ?ServiceClient
    {
        $stmt = $this->pdo->prepare('SELECT * FROM service_clients WHERE client_id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    /**
     * @param list<string> $allowedScopes
     */
    public function create(
        string $clientId,
        string $name,
        string $plaintextSecret,
        array $allowedScopes,
        ?string $defaultAudience = null,
        bool $enabled = true,
    ): ServiceClient {
        if ($clientId === '' || $name === '') {
            throw new \InvalidArgumentException('client_id and name are required');
        }
        if ($allowedScopes === []) {
            throw new \InvalidArgumentException('at least one allowed scope is required');
        }

        $now = gmdate('Y-m-d H:i:s');
        $scopesJson = json_encode(array_values($allowedScopes), JSON_THROW_ON_ERROR);
        $stmt = $this->pdo->prepare(
            'INSERT INTO service_clients
             (client_id, client_secret_hash, name, allowed_scopes, default_audience, enabled, created_at, updated_at)
             VALUES
             (:client_id, :hash, :name, :scopes, :aud, :enabled, :created_at, :updated_at)'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'hash' => ClientSecretHasher::hash($plaintextSecret),
            'name' => $name,
            'scopes' => $scopesJson,
            'aud' => $defaultAudience,
            'enabled' => $enabled ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $client = $this->findByClientId($clientId);
        if ($client === null) {
            throw new \RuntimeException('Failed to persist service client: ' . $clientId);
        }

        return $client;
    }

    /**
     * Rotate client secret. Returns the new plaintext secret (show once).
     */
    public function rotateSecret(string $clientId): string
    {
        if ($this->findByClientId($clientId) === null) {
            throw new \InvalidArgumentException('Unknown service client: ' . $clientId);
        }
        $plaintext = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $stmt = $this->pdo->prepare(
            'UPDATE service_clients
             SET client_secret_hash = :hash, updated_at = :updated_at
             WHERE client_id = :client_id'
        );
        $stmt->execute([
            'hash' => ClientSecretHasher::hash($plaintext),
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'client_id' => $clientId,
        ]);

        return $plaintext;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): ServiceClient
    {
        $scopes = json_decode((string) $row['allowed_scopes'], true);
        if (!is_array($scopes)) {
            $scopes = [];
        }
        $scopes = array_values(array_map('strval', $scopes));

        return new ServiceClient(
            (string) $row['client_id'],
            (string) $row['client_secret_hash'],
            (string) $row['name'],
            $scopes,
            $row['default_audience'] !== null ? (string) $row['default_audience'] : null,
            (bool) $row['enabled'],
        );
    }
}
