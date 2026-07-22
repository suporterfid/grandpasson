<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Auth\AuthCodeService;
use GrandpaSSOn\Infrastructure\Auth\JwtAccessTokenFactory;
use GrandpaSSOn\Infrastructure\Auth\OAuthClientAuthenticator;
use GrandpaSSOn\Infrastructure\Auth\ServiceClientAuthenticator;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Support\AccessTokenTtl;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

final class OAuthTokenController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function token(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowDb($pdo, 'oauth_token')) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        $grantType = (string) ($body['grant_type'] ?? '');
        $audit = new AuditLogger($pdo);

        if ($grantType === 'authorization_code') {
            $this->authorizationCode($config, $pdo, $audit, $body);

            return;
        }

        if ($grantType !== 'client_credentials') {
            $clientId = (string) ($body['client_id'] ?? '');
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
                'error_description' => 'Supported: client_credentials, authorization_code',
            ]);

            return;
        }

        $this->clientCredentials($config, $pdo, $audit, $body);
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $body
     */
    private function clientCredentials(array $config, \PDO $pdo, AuditLogger $audit, array $body): void
    {
        $clientId = (string) ($body['client_id'] ?? '');
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $scopeRaw = trim((string) ($body['scope'] ?? ''));
        $audience = trim((string) ($body['audience'] ?? $body['aud'] ?? ''));

        $auth = new ServiceClientAuthenticator(new ServiceClientRepository($pdo));
        $client = $auth->authenticate($clientId, $clientSecret);
        if ($client === null) {
            $audit->record(
                action: 'token.issue',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId !== '' ? $clientId : null,
                clientId: $clientId !== '' ? $clientId : null,
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
        ] + self::optionalJwt($config, $issued['record'], $pdo));
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, string>
     */
    private static function optionalJwt(array $config, \GrandpaSSOn\Domain\AccessToken $record, \PDO $pdo): array
    {
        if (!JwtAccessTokenFactory::enabled($config, $pdo)) {
            return [];
        }

        return ['jwt' => JwtAccessTokenFactory::mint($config, $record, $pdo)];
    }

    /**
     * RP authorization_code grant (R11). Public clients require PKCE; confidential require secret.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $body
     */
    private function authorizationCode(array $config, \PDO $pdo, AuditLogger $audit, array $body): void
    {
        $code = (string) ($body['code'] ?? '');
        $clientId = (string) ($body['client_id'] ?? '');
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $redirectUri = (string) ($body['redirect_uri'] ?? '');
        $codeVerifier = (string) ($body['code_verifier'] ?? '');
        $scopeRaw = trim((string) ($body['scope'] ?? 'openid profile email tenant:read'));

        if ($code === '' || $clientId === '' || $redirectUri === '') {
            $audit->record(
                action: 'token.issue',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId !== '' ? $clientId : null,
                clientId: $clientId !== '' ? $clientId : null,
                target: 'invalid_request',
                ip: Http::clientIp(),
            );
            Http::json(400, ['error' => 'invalid_request']);

            return;
        }

        $repo = new OAuthClientRepository($pdo);
        $lookedUp = $repo->findByClientId($clientId);

        // Public clients: no secret. Unknown / confidential: always verify (dummy hash if needed).
        if ($lookedUp !== null && !$lookedUp->isConfidential()) {
            if ($clientSecret !== '') {
                $audit->record(
                    action: 'token.issue',
                    result: AuditLogger::RESULT_FAILURE,
                    actorType: AuditLogger::ACTOR_SERVICE,
                    actorId: $clientId,
                    clientId: $clientId,
                    target: 'public_with_secret',
                    ip: Http::clientIp(),
                );
                Http::json(401, ['error' => 'invalid_client']);

                return;
            }
            if (!$lookedUp->enabled) {
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
            $client = $lookedUp;
        } else {
            $client = (new OAuthClientAuthenticator($repo))->authenticateConfidential($clientId, $clientSecret);
            if ($client === null) {
                $audit->record(
                    action: 'token.issue',
                    result: AuditLogger::RESULT_FAILURE,
                    actorType: AuditLogger::ACTOR_SERVICE,
                    actorId: $clientId !== '' ? $clientId : null,
                    clientId: $clientId !== '' ? $clientId : null,
                    ip: Http::clientIp(),
                );
                Http::json(401, ['error' => 'invalid_client']);

                return;
            }
        }

        if (!$client->allowsRedirectUri($redirectUri)) {
            Http::json(400, ['error' => 'invalid_grant']);

            return;
        }

        // Public clients always require PKCE verifier; confidential require it when code was bound.
        $verifier = $codeVerifier !== '' ? $codeVerifier : null;
        if (!$client->isConfidential() && ($verifier === null || $verifier === '')) {
            Http::json(400, [
                'error' => 'invalid_grant',
                'error_description' => 'code_verifier required for public clients',
            ]);

            return;
        }

        $userId = (new AuthCodeService($pdo))->consume($code, $clientId, $redirectUri, $verifier);
        if ($userId === null) {
            $audit->record(
                action: 'token.issue',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SERVICE,
                actorId: $clientId,
                clientId: $clientId,
                target: 'invalid_grant',
                ip: Http::clientIp(),
            );
            Http::json(400, ['error' => 'invalid_grant']);

            return;
        }

        $scopes = preg_split('/\s+/', $scopeRaw, -1, PREG_SPLIT_NO_EMPTY) ?: ['openid', 'profile', 'email', 'tenant:read'];
        $ttl = AccessTokenTtl::resolve($config['tokens'] ?? []);
        $issued = (new AccessTokenRepository($pdo))->issueForOauthUser(
            $clientId,
            $userId,
            implode(' ', $scopes),
            null,
            $ttl,
        );

        $audit->record(
            action: 'token.issued',
            result: AuditLogger::RESULT_SUCCESS,
            actorType: AuditLogger::ACTOR_SUBJECT,
            actorId: $userId,
            clientId: $clientId,
            target: 'token:' . $issued['record']->id,
            ip: Http::clientIp(),
        );

        Http::json(200, [
            'access_token' => $issued['token'],
            'token_type' => 'Bearer',
            'expires_in' => $issued['expires_in'],
            'scope' => implode(' ', $scopes),
            'sub' => $userId,
        ] + self::optionalJwt($config, $issued['record'], $pdo));
    }
}
