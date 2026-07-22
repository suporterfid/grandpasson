<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Auth\AuthCodeService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;

final class SessionExchangeController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function exchange(array $config, array $params = []): void
    {
        if (!RateLimitGate::allow('exchange')) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        $code = (string) ($body['code'] ?? '');
        $clientId = (string) ($body['client_id'] ?? '');
        $clientSecret = (string) ($body['client_secret'] ?? '');
        $redirectUri = (string) ($body['redirect_uri'] ?? '');

        $pdo = Connection::get($config['db']);
        $audit = new AuditLogger($pdo);

        if ($code === '' || $clientId === '' || $clientSecret === '' || $redirectUri === '') {
            $audit->log('exchange.failure', null, null, Http::clientIp());
            Http::json(400, ['error' => 'invalid_request']);

            return;
        }

        $client = (new OAuthClientRepository($pdo))->findByClientId($clientId);
        if ($client === null || !$client->enabled) {
            $audit->log('exchange.failure', null, null, Http::clientIp());
            Http::json(401, ['error' => 'invalid_client']);

            return;
        }
        if (!$client->isConfidential() || $client->clientSecretHash === null || $client->clientSecretHash === '') {
            $audit->log('exchange.failure', null, null, Http::clientIp());
            Http::json(401, ['error' => 'unauthorized_client']);

            return;
        }
        if (!password_verify($clientSecret, $client->clientSecretHash)) {
            $audit->log('exchange.failure', null, null, Http::clientIp());
            Http::json(401, ['error' => 'invalid_client']);

            return;
        }
        if (!$client->allowsRedirectUri($redirectUri)) {
            $audit->log('exchange.failure', null, null, Http::clientIp());
            Http::json(400, ['error' => 'invalid_redirect_uri']);

            return;
        }

        $userId = (new AuthCodeService($pdo))->consume($code, $clientId, $redirectUri);
        if ($userId === null) {
            $audit->log('exchange.failure', null, null, Http::clientIp());
            Http::json(400, ['error' => 'invalid_grant']);

            return;
        }

        $stmt = $pdo->prepare('SELECT id, primary_email, display_name, status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            $audit->log('exchange.failure', $userId, null, Http::clientIp());
            Http::json(400, ['error' => 'invalid_grant']);

            return;
        }

        $audit->log('exchange.success', $userId, null, Http::clientIp());
        Http::json(200, [
            'id' => $row['id'],
            'email' => $row['primary_email'],
            'display_name' => $row['display_name'],
            'status' => $row['status'],
        ]);
    }
}
