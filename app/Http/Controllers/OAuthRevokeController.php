<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Auth\ServiceClientAuthenticator;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

/**
 * RFC 7009-style revoke: authenticated clients; always HTTP 200 (anti-enumeration).
 */
final class OAuthRevokeController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function revoke(array $config, array $params = []): void
    {
        if (!RateLimitGate::allow('oauth_revoke')) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        $clientId = (string) ($body['client_id'] ?? '');
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $token = (string) ($body['token'] ?? '');

        $pdo = Connection::get($config['db']);
        $audit = new AuditLogger($pdo);
        $auth = new ServiceClientAuthenticator(new ServiceClientRepository($pdo));
        $caller = $auth->authenticate($clientId, $clientSecret);

        if ($caller === null) {
            $audit->record(
                action: 'token.revoke',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId !== '' ? $clientId : null,
                clientId: $clientId !== '' ? $clientId : null,
                ip: Http::clientIp(),
            );
            Http::json(401, ['error' => 'invalid_client']);

            return;
        }

        $tokens = new AccessTokenRepository($pdo);
        $revoked = 0;
        if ($token !== '') {
            $record = $tokens->findByToken($token);
            // Only revoke tokens issued to the calling client (no cross-client revoke).
            if ($record !== null && $record->clientId === $caller->clientId) {
                $revoked = $tokens->revokeById($record->id);
            }
        }

        $audit->record(
            action: 'token.revoke',
            result: AuditLogger::RESULT_SUCCESS,
            actorType: AuditLogger::ACTOR_SERVICE,
            actorId: $caller->clientId,
            clientId: $caller->clientId,
            target: $revoked > 0 ? 'revoked' : 'noop',
            ip: Http::clientIp(),
        );

        // RFC 7009: always 200 with empty body semantics; we return JSON ok for consistency.
        Http::json(200, ['revoked' => true]);
    }
}
