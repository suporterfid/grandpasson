<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use GrandpaSSOn\Domain\OAuthClient;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;

/**
 * Authenticate confidential RP oauth_clients without leaking client_id existence (S3).
 * Mirrors ServiceClientAuthenticator: always password_verify against a real or dummy hash.
 */
final class OAuthClientAuthenticator
{
    public function __construct(private readonly OAuthClientRepository $clients)
    {
    }

    /**
     * Returns an enabled confidential client when the secret matches.
     * Unknown, disabled, public, or bad-secret clients all return null after a verify.
     */
    public function authenticateConfidential(string $clientId, string $clientSecret): ?OAuthClient
    {
        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        $client = $this->clients->findByClientId($clientId);
        $hash = (
            $client !== null
            && $client->isConfidential()
            && $client->clientSecretHash !== null
            && $client->clientSecretHash !== ''
        )
            ? $client->clientSecretHash
            : self::dummySecretHash();

        $ok = ClientSecretHasher::verify($clientSecret, $hash);

        if (
            $client === null
            || !$client->enabled
            || !$client->isConfidential()
            || $client->clientSecretHash === null
            || $client->clientSecretHash === ''
            || !$ok
        ) {
            return null;
        }

        return $client;
    }

    private static function dummySecretHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            $hash = ClientSecretHasher::hash('grandpasson-dummy-rp-secret-not-a-real-client');
        }

        return $hash;
    }
}
