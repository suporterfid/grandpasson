<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Providers\ProviderFactory;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Html;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;
use GrandpaSSOn\Support\RpRequestValidator;

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
        $query = http_build_query($_GET);
        $emailHref = Html::e(Html::basePath($config) . '/login/email' . ($query !== '' ? '?' . $query : ''));
        echo '<p class="text-small"><a href="' . $emailHref . '">Or continue with email</a></p>';
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

        $audit = new AuditLogger($pdo);
        $validated = RpRequestValidator::validate($pdo, $_GET);
        if (!$validated->ok) {
            if ($validated->error === 'disabled_client') {
                $audit->log('login.disabled_client', null, $providerName, Http::clientIp());
            }
            Http::json($validated->status, ['error' => $validated->error, 'message' => $validated->message]);

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
            'client_id' => $validated->clientId,
            'redirect_uri' => $validated->redirectUri,
            'client_state' => $validated->clientState,
            'return_to' => $validated->returnTo,
            'rp_code_challenge' => $validated->codeChallenge,
            'rp_code_challenge_method' => $validated->codeChallengeMethod,
        ];
        Csrf::token();

        Http::redirect($provider->getAuthorizationUrl($oauthState, $nonce, $pkce));
    }
}
