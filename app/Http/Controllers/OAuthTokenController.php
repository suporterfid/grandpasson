<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Auth\ClientSecretHasher;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Support\AccessTokenTtl;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

final class OAuthTokenController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function token(array $config, array $params = []): void
    {
        if (!RateLimitGate::allow('oauth_token')) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        $grantType = (string) ($body['grant_type'] ?? '');
        $clientId = (string) ($body['client_id'] ?? '');
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $scopeRaw = trim((string) ($body['scope'] ?? ''));
        $audience = trim((string) ($body['audience'] ?? $body['aud'] ?? ''));

        $pdo = Connection::get($config['db']);
        $audit = new AuditLogger($pdo);
        $clients = new ServiceClientRepository($pdo);

        if ($grantType !== 'client_credentials') {
            $audit->record(
                action: 'token.issue',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId !== '' ? $clientId : null,
                clientId: $clientId !== '' ? $clientId : null,
                ip: Http::clientIp(),
            );
            Http::json(400, [
                'error' => 'unsupported_grant_type',
                'error_description' => 'Only client_credentials is supported',
            ]);

            return;
        }

        if ($clientId === '' || $clientSecret === '') {
            $audit->record(
                action: 'token.issue',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                ip: Http::clientIp(),
            );
            Http::json(401, ['error' => 'invalid_client']);

            return;
        }

        $client = $clients->findByClientId($clientId);
        $hash = ($client !== null && $client->clientSecretHash !== '')
            ? $client->clientSecretHash
            : self::dummySecretHash();
        $secretOk = ClientSecretHasher::verify($clientSecret, $hash);

        if ($client === null || !$client->enabled || !$secretOk) {
            $audit->record(
                action: 'token.issue',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId,
                clientId: $clientId,
                ip: Http::clientIp(),
            );
            Http::json(401, ['error' => 'invalid_client']);

            return;
        }

        $requested = $scopeRaw === ''
            ? $client->allowedScopes
            : (preg_split('/\s+/', $scopeRaw, -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($requested === [] || !$client->allowsAllScopes($requested)) {
            $audit->record(
                action: 'token.issue',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId,
                clientId: $clientId,
                target: 'invalid_scope',
                ip: Http::clientIp(),
            );
            Http::json(400, ['error' => 'invalid_scope']);

            return;
        }

        if (
            $audience !== ''
            && $client->defaultAudience !== null
            && $audience !== $client->defaultAudience
        ) {
            $audit->record(
                action: 'token.issue',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId,
                clientId: $clientId,
                target: 'invalid_audience',
                ip: Http::clientIp(),
            );
            Http::json(400, [
                'error' => 'invalid_request',
                'error_description' => 'audience is not allowed for this client',
            ]);

            return;
        }

        $aud = $audience !== '' ? $audience : $client->defaultAudience;
        $ttl = AccessTokenTtl::resolve($config['tokens'] ?? []);
        $issued = (new AccessTokenRepository($pdo))->issue(
            $clientId,
            implode(' ', $requested),
            $aud,
            $ttl,
        );

        $audit->record(
            action: 'token.issued',
            result: AuditLogger::RESULT_SUCCESS,
            actorType: AuditLogger::ACTOR_SERVICE,
            actorId: $clientId,
            clientId: $clientId,
            target: 'token:' . $issued['record']->id,
            ip: Http::clientIp(),
        );

        Http::json(200, [
            'access_token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
            'scope' => implode(' ', $requested),
            'aud' => $aud,
        ]);
    }

    /** Same algorithm/cost as ClientSecretHasher for unknown-client verify path. */
    private static function dummySecretHash(): string
    {
        static $hash = null;
        if ($hash === null) {
            $hash = ClientSecretHasher::hash('grandpasson-dummy-secret-not-a-real-client');
        }

        return $hash;
    }
}
