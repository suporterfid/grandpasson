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

final class OAuthIntrospectController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function introspect(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowDb($pdo, 'oauth_introspect')) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        $clientId = (string) ($body['client_id'] ?? '');
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $token = (string) ($body['token'] ?? '');

        $audit = new AuditLogger($pdo);
        $auth = new ServiceClientAuthenticator(new ServiceClientRepository($pdo));
        $caller = $auth->authenticate($clientId, $clientSecret);

        if ($caller === null) {
            $audit->record(
                action: 'token.introspect',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId !== '' ? $clientId : null,
                clientId: $clientId !== '' ? $clientId : null,
                ip: Http::clientIp(),
            );
            Http::json(401, ['error' => 'invalid_client']);

            return;
        }

        if ($token === '') {
            Http::json(200, ['active' => false]);

            return;
        }

        $tokens = new AccessTokenRepository($pdo);
        $record = $tokens->findByToken($token);
        if ($record === null || !$record->isActive()) {
            $audit->record(
                action: 'token.introspect',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $caller->clientId,
                clientId: $caller->clientId,
                target: 'inactive',
                ip: Http::clientIp(),
            );
            Http::json(200, ['active' => false]);

            return;
        }

        // Re-check revoked/expired under UPDATE so concurrent revoke wins (S5).
        if (!$tokens->touchLastUsedIfActive($record->id)) {
            $audit->record(
                action: 'token.introspect',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $caller->clientId,
                clientId: $caller->clientId,
                target: 'inactive',
                ip: Http::clientIp(),
            );
            Http::json(200, ['active' => false]);

            return;
        }

        $exp = strtotime($record->expiresAt . ' UTC');
        if ($exp === false) {
            Http::json(200, ['active' => false]);

            return;
        }

        Http::json(200, [
            'active' => true,
            'sub' => $record->subjectUserId,
            'client_id' => $record->clientId,
            'scope' => $record->scope,
            'aud' => $record->aud,
            'tenant' => $record->tenantId,
            'exp' => $exp,
            'token_use' => $record->isPat() ? 'pat' : 'access',
            'token_type' => $record->kind,
        ]);
    }
}
