<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Providers\ProviderFactory;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Html;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

final class LoginController
{
    private const PROVIDER_LABELS = [
        'google' => 'Google',
        'microsoft' => 'Microsoft',
        'github' => 'GitHub',
    ];

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function chooser(array $config, array $params = []): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');

        echo Html::pageStart($config, $name . ' Login');
        echo '<div class="prose">';
        echo '<h1>' . Html::e($name) . '</h1>';
        echo '<p class="lead">Choose a provider (pass client_id, redirect_uri, and state on the provider URL).</p>';
        echo '<ul class="action-list">';
        foreach (self::PROVIDER_LABELS as $provider => $label) {
            $href = Html::e(Html::basePath($config) . '/login/' . rawurlencode($provider));
            echo '<li><a class="btn btn--secondary" href="' . $href . '">Continue with ' . Html::e($label) . '</a></li>';
        }
        echo '</ul>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function start(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowLogin($pdo, 'login', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $providerName = strtolower($params['provider'] ?? '');
        if (!in_array($providerName, ['google', 'microsoft', 'github'], true)) {
            Http::json(400, ['error' => 'invalid_provider', 'message' => 'Unknown provider']);

            return;
        }

        $clientId = (string) ($_GET['client_id'] ?? '');
        $redirectUri = (string) ($_GET['redirect_uri'] ?? '');
        $clientState = (string) ($_GET['state'] ?? '');
        $returnTo = (string) ($_GET['return_to'] ?? '');
        $rpChallenge = trim((string) ($_GET['code_challenge'] ?? ''));
        $rpChallengeMethod = strtoupper(trim((string) ($_GET['code_challenge_method'] ?? 'S256')));

        if ($clientId === '' || $redirectUri === '' || $clientState === '') {
            Http::json(400, ['error' => 'invalid_request', 'message' => 'client_id, redirect_uri, and state are required']);

            return;
        }

        $clients = new OAuthClientRepository($pdo);
        $audit = new AuditLogger($pdo);
        $client = $clients->findByClientId($clientId);

        if ($client === null) {
            Http::json(400, ['error' => 'invalid_client', 'message' => 'Unknown client_id']);

            return;
        }
        if (!$client->enabled) {
            $audit->log('login.disabled_client', null, $providerName, Http::clientIp());
            Http::json(403, ['error' => 'disabled_client', 'message' => 'OAuth client is disabled']);

            return;
        }
        if (!$client->allowsRedirectUri($redirectUri)) {
            Http::json(400, ['error' => 'invalid_redirect_uri', 'message' => 'redirect_uri does not match registered URIs']);

            return;
        }

        // S6 / R11: public clients must use PKCE (S256).
        if (!$client->isConfidential()) {
            if ($rpChallenge === '' || $rpChallengeMethod !== 'S256') {
                Http::json(400, [
                    'error' => 'invalid_request',
                    'message' => 'public clients require code_challenge with code_challenge_method=S256',
                ]);

                return;
            }
        } elseif ($rpChallenge !== '' && $rpChallengeMethod !== 'S256') {
            Http::json(400, [
                'error' => 'invalid_request',
                'message' => 'Only S256 code_challenge_method is supported',
            ]);

            return;
        }

        try {
            $factory = new ProviderFactory($config);
            $provider = $factory->make($providerName);
        } catch (ProviderException $e) {
            Http::json(500, ['error' => 'provider_config', 'message' => $e->getMessage()]);

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
            'rp_code_challenge' => $rpChallenge !== '' ? $rpChallenge : null,
            'rp_code_challenge_method' => $rpChallenge !== '' ? $rpChallengeMethod : null,
        ];
        Csrf::token();

        Http::redirect($provider->getAuthorizationUrl($oauthState, $nonce, $pkce));
    }
}
