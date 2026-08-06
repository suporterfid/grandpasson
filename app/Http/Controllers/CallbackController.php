<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Auth\AuthCodeService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Providers\ProviderFactory;
use GrandpaSSOn\Infrastructure\Provisioning\UserProvisioner;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Html;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

final class CallbackController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function handle(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowLogin($pdo, 'callback', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $providerName = strtolower($params['provider'] ?? '');
        $oauth = $_SESSION['oauth'] ?? null;

        // R14: reader IdP callbacks reuse the same redirect_uri as editor login.
        if (isset($_SESSION['reader_oauth']) && is_array($_SESSION['reader_oauth'])) {
            $reader = $_SESSION['reader_oauth'];
            if (($reader['provider'] ?? '') === $providerName) {
                (new SiteReaderController())->callback($config, [
                    'site_id' => (string) ($reader['site_id'] ?? ''),
                    'provider' => $providerName,
                ]);

                return;
            }
        }

        if (!is_array($oauth) || ($oauth['provider'] ?? '') !== $providerName) {
            $this->fail($config, 400, 'invalid_state', 'Login session missing or provider mismatch');

            return;
        }

        $returnedState = (string) ($_GET['state'] ?? '');
        if ($returnedState === '' || !hash_equals((string) $oauth['state'], $returnedState)) {
            $this->fail($config, 400, 'invalid_state', 'OAuth state mismatch');

            return;
        }

        if (isset($_GET['error'])) {
            $this->fail($config, 400, 'provider_error', (string) ($_GET['error_description'] ?? $_GET['error']));

            return;
        }

        $audit = new AuditLogger($pdo);

        try {
            $factory = new ProviderFactory($config);
            $provider = $factory->make($providerName);

            if (method_exists($provider, 'setExpectedNonce') && !empty($oauth['nonce'])) {
                $provider->setExpectedNonce((string) $oauth['nonce']);
            }

            $identity = $provider->handleCallback([
                'code' => (string) ($_GET['code'] ?? ''),
                'code_verifier' => (string) (($oauth['pkce']['code_verifier'] ?? '')),
                'nonce' => $oauth['nonce'] ?? null,
            ]);

            $provisioner = new UserProvisioner($pdo);
            $user = $provisioner->resolve($identity);

            session_regenerate_id(true);
            $_SESSION['user_id'] = $user->id;
            $_SESSION['email'] = $user->primaryEmail;
            $_SESSION['display_name'] = $user->displayName;
            $_SESSION['status'] = $user->status;
            $_SESSION['avatar_url'] = $user->avatarUrl;
            Csrf::token();

            $codes = new AuthCodeService($pdo);
            $rawCode = $codes->mint(
                $user->id,
                (string) $oauth['client_id'],
                (string) $oauth['redirect_uri'],
                isset($oauth['rp_code_challenge']) ? (string) $oauth['rp_code_challenge'] : null,
                isset($oauth['rp_code_challenge_method']) ? (string) $oauth['rp_code_challenge_method'] : null,
            );

            $clientState = (string) $oauth['client_state'];
            $redirectUri = (string) $oauth['redirect_uri'];
            unset($_SESSION['oauth']);

            $audit->log('login.success', $user->id, $providerName, Http::clientIp());

            $sep = str_contains($redirectUri, '?') ? '&' : '?';
            $target = $redirectUri . $sep . http_build_query([
                'code' => $rawCode,
                'state' => $clientState,
            ]);
            Http::redirect($target);
        } catch (ProviderException $e) {
            $audit->log('login.failure', null, $providerName, Http::clientIp());
            $this->fail($config, 400, 'login_failed', $e->getMessage());
        } catch (\Throwable $e) {
            $audit->log('login.failure', null, $providerName, Http::clientIp());
            $this->fail($config, 500, 'server_error', 'Callback processing failed');
        }
    }

    /** @param array<string, mixed> $config */
    private function fail(array $config, int $status, string $error, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Error: ' . $error);
        echo Html::pageStart($config, 'Login failed');
        echo '<div class="prose">';
        echo '<h1>Login failed</h1>';
        echo '<p>' . Html::e($message) . '</p>';
        $loginHref = Html::e(Html::basePath($config) . '/login');
        echo '<p><a class="btn btn--primary" href="' . $loginHref . '" rel="nofollow noreferrer">Try again</a></p>';
        echo '</div>';
        echo Html::pageEnd();
    }
}
