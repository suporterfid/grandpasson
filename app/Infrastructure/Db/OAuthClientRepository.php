<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\OAuthClient;
use GrandpaSSOn\Infrastructure\Auth\ClientSecretHasher;
use PDO;

final class OAuthClientRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByClientId(string $clientId): ?OAuthClient
    {
        $stmt = $this->pdo->prepare('SELECT * FROM oauth_clients WHERE client_id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->map($row);
    }

    /**
     * Insert or replace a confidential/public client registration.
     *
     * @param list<string> $redirectUris
     */
    public function upsert(
        string $clientId,
        string $name,
        array $redirectUris,
        string $type,
        ?string $plaintextSecret,
        bool $enabled = true,
    ): OAuthClient {
        if ($redirectUris === []) {
            throw new \InvalidArgumentException('At least one redirect_uri is required');
        }
        if (!in_array($type, ['confidential', 'public'], true)) {
            throw new \InvalidArgumentException('type must be confidential or public');
        }
        if ($type === 'confidential' && ($plaintextSecret === null || $plaintextSecret === '')) {
            throw new \InvalidArgumentException('confidential clients require a client_secret');
        }

        $hash = null;
        if ($type === 'confidential') {
            $hash = ClientSecretHasher::hash((string) $plaintextSecret);
        }

        $urisJson = json_encode(array_values($redirectUris), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $stmt = $this->pdo->prepare(
            'INSERT INTO oauth_clients (client_id, client_secret_hash, name, redirect_uris, type, enabled)
             VALUES (:client_id, :hash, :name, :uris, :type, :enabled)
             ON DUPLICATE KEY UPDATE
               client_secret_hash = VALUES(client_secret_hash),
               name = VALUES(name),
               redirect_uris = VALUES(redirect_uris),
               type = VALUES(type),
               enabled = VALUES(enabled)'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'hash' => $hash,
            'name' => $name,
            'uris' => $urisJson,
            'type' => $type,
            'enabled' => $enabled ? 1 : 0,
        ]);

        $client = $this->findByClientId($clientId);
        if ($client === null) {
            throw new \RuntimeException('Failed to persist oauth client: ' . $clientId);
        }

        return $client;
    }

    public function setEnabled(string $clientId, bool $enabled): void
    {
        $stmt = $this->pdo->prepare('UPDATE oauth_clients SET enabled = :enabled WHERE client_id = :id');
        $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'id' => $clientId,
        ]);
        if ($stmt->rowCount() === 0 && $this->findByClientId($clientId) === null) {
            throw new \InvalidArgumentException('Unknown client_id: ' . $clientId);
        }
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): OAuthClient
    {
        $uris = json_decode((string) $row['redirect_uris'], true);
        if (!is_array($uris)) {
            $uris = array_values(array_filter(array_map('trim', explode("\n", (string) $row['redirect_uris']))));
        }

        /** @var list<string> $uris */
        $uris = array_values(array_map('strval', $uris));

        return new OAuthClient(
            clientId: (string) $row['client_id'],
            clientSecretHash: $row['client_secret_hash'] !== null ? (string) $row['client_secret_hash'] : null,
            name: (string) $row['name'],
            redirectUris: $uris,
            type: (string) $row['type'],
            enabled: (bool) $row['enabled'],
        );
    }
}
