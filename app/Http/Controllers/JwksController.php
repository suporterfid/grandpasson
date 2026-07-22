<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\JwtSigningKeyRepository;
use GrandpaSSOn\Support\Http;

/** JWKS for rotatable RS256 JWT signing keys (R16). */
final class JwksController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function show(array $config, array $params = []): void
    {
        if (!(bool) (($config['jwt']['enabled'] ?? false))) {
            Http::json(200, ['keys' => []]);

            return;
        }

        try {
            $pdo = Connection::get($config['db']);
            $jwt = is_array($config['jwt'] ?? null) ? $config['jwt'] : [];
            $jwks = (new JwtSigningKeyRepository(
                $pdo,
                (string) ($jwt['key_encryption_secret'] ?? ''),
                (string) ($config['app_env'] ?? 'dev'),
            ))->jwks();
            Http::json(200, $jwks);
        } catch (\Throwable) {
            Http::json(200, ['keys' => []]);
        }
    }
}
