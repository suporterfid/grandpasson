<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\OAuthClient;
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
