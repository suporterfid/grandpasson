<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use GrandpaSSOn\Domain\ServiceClient;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;

/**
 * Authenticate service clients without leaking whether client_id exists (S3 / §6.1).
 */
final class ServiceClientAuthenticator
{
    public function __construct(private readonly ServiceClientRepository $clients)
    {
    }

    public function authenticate(string $clientId, string $clientSecret): ?ServiceClient
    {
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $client = $this->clients->findByClientId($clientId);
        $hash = ($client !== null && $client->clientSecretHash !== '')
            ? $client->clientSecretHash
            : self::dummySecretHash();
        $ok = ClientSecretHasher::verify($clientSecret, $hash);

        if ($client === null || !$client->enabled || !$ok) {
            return null;
        }

        return $client;
    }

    private static function dummySecretHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            $hash = ClientSecretHasher::hash('grandpasson-dummy-secret-not-a-real-client');
        }

        return $hash;
    }
}
