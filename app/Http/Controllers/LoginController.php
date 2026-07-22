<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Providers\ProviderFactory;

final class LoginController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function chooser(array $config, array $params = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = htmlspecialchars((string) ($config['broker']['name'] ?? 'GrandpaSSOn'), ENT_QUOTES);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $name . ' Login</title></head><body>';
        echo '<h1>' . $name . '</h1><p>Choose a provider (pass client_id, redirect_uri, and state on the provider URL).</p><ul>';
        foreach (['google', 'microsoft', 'github'] as $provider) {
            echo '<li><a href="/login/' . $provider . '">' . htmlspecialchars($provider, ENT_QUOTES) . '</a></li>';
        }
        echo '</ul></body></html>';
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function start(array $config, array $params = []): void
    {
        $providerName = strtolower($params['provider'] ?? '');
        if (!in_array($providerName, ['google', 'microsoft', 'github'], true)) {
            $this->jsonError(400, 'invalid_provider', 'Unknown provider');

            return;
        }

        $clientId = (string) ($_GET['client_id'] ?? '');
        $redirectUri = (string) ($_GET['redirect_uri'] ?? '');
        $clientState = (string) ($_GET['state'] ?? '');
        $returnTo = (string) ($_GET['return_to'] ?? '');

        if ($clientId === '' || $redirectUri === '' || $clientState === '') {
            $this->jsonError(400, 'invalid_request', 'client_id, redirect_uri, and state are required');

            return;
        }

        $pdo = Connection::get($config['db']);
        $clients = new OAuthClientRepository($pdo);
        $audit = new AuditLogger($pdo);
        $client = $clients->findByClientId($clientId);

        if ($client === null) {
            $this->jsonError(400, 'invalid_client', 'Unknown client_id');

            return;
        }
        if (!$client->enabled) {
            $audit->log('login.disabled_client', null, $providerName, $_SERVER['REMOTE_ADDR'] ?? null);
            $this->jsonError(403, 'disabled_client', 'OAuth client is disabled');

            return;
        }
        if (!$client->allowsRedirectUri($redirectUri)) {
            $this->jsonError(400, 'invalid_redirect_uri', 'redirect_uri does not match registered URIs');

            return;
        }

        try {
            $factory = new ProviderFactory($config);
            $provider = $factory->make($providerName);
        } catch (ProviderException $e) {
            $this->jsonError(500, 'provider_config', $e->getMessage());

            return;
        }

        $oauthState = bin2hex(random_bytes(16));
        $nonce = in_array($providerName, ['google', 'microsoft'], true) ? bin2hex(random_bytes(16)) : null;
        $pkce = Pkce::generate();

        $_SESSION['oauth'] = [
            'provider' => $providerName,
            'state' => $oauthState,
            'nonce' => $nonce,
            'pkce' => $pkce,
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'client_state' => $clientState,
            'return_to' => $returnTo,
        ];
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }

        $url = $provider->getAuthorizationUrl($oauthState, $nonce, $pkce);
        header('Location: ' . $url, true, 302);
        exit;
    }

    private function jsonError(int $status, string $error, string $message): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $error, 'message' => $message], JSON_THROW_ON_ERROR);
    }
}
