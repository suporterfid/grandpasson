<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Domain\PublishedSite;
use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\PublishedSiteRepository;
use GrandpaSSOn\Infrastructure\Db\ReaderSessionRepository;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Providers\ProviderFactory;
use GrandpaSSOn\Infrastructure\Provisioning\UserProvisioner;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

/**
 * Gated-publishing reader flow (R14) — isolated from editor AUTHSESSID sessions.
 */
final class SiteReaderController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function chooser(array $config, array $params = []): void
    {
        $siteId = (string) ($params['site_id'] ?? '');
        if ($siteId === '') {
            Http::json(400, ['error' => 'invalid_request', 'message' => 'site_id required']);

            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowLogin($pdo, 'reader_login_chooser', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $site = (new PublishedSiteRepository($pdo))->findBySiteId($siteId);
        if ($site === null || !$site->enabled) {
            Http::json(404, ['error' => 'site_not_found']);

            return;
        }
        if ($site->visibility === PublishedSite::VIS_PUBLIC) {
            Http::json(400, ['error' => 'login_not_required', 'message' => 'Site is public']);

            return;
        }

        $default = strtolower(trim((string) ($_GET['provider'] ?? '')));
        if (in_array($default, ['google', 'microsoft', 'github'], true)) {
            $this->login($config, ['site_id' => $siteId, 'provider' => $default]);

            return;
        }

        $safeSite = htmlspecialchars($siteId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $name = htmlspecialchars((string) ($config['broker']['name'] ?? 'GrandpaSSOn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>' . $name . ' reader login</title></head><body>';
        echo '<h1>' . $name . ' reader login</h1>';
        echo '<p>Sign in to read site <code>' . $safeSite . '</code> (publish:read only; not an editor session).</p><ul>';
        foreach (['google', 'microsoft', 'github'] as $provider) {
            $href = '/site/' . rawurlencode($siteId) . '/login/' . $provider;
            echo '<li><a href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($provider, ENT_QUOTES) . '</a></li>';
        }
        echo '</ul></body></html>';
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function login(array $config, array $params = []): void
    {
        $siteId = (string) ($params['site_id'] ?? '');
        $providerName = strtolower((string) ($params['provider'] ?? ($_GET['provider'] ?? '')));
        if ($siteId === '' || !in_array($providerName, ['google', 'microsoft', 'github'], true)) {
            Http::json(400, ['error' => 'invalid_request', 'message' => 'site_id and provider required']);

            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowLogin($pdo, 'reader_login', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }
        $site = (new PublishedSiteRepository($pdo))->findBySiteId($siteId);
        if ($site === null || !$site->enabled) {
            Http::json(404, ['error' => 'site_not_found']);

            return;
        }
        if ($site->visibility === PublishedSite::VIS_PUBLIC) {
            Http::json(400, ['error' => 'login_not_required', 'message' => 'Site is public']);

            return;
        }

        try {
            $provider = (new ProviderFactory($config))->make($providerName);
        } catch (ProviderException $e) {
            Http::json(500, ['error' => 'provider_config', 'message' => $e->getMessage()]);

            return;
        }

        $oauthState = bin2hex(random_bytes(16));
        $nonce = in_array($providerName, ['google', 'microsoft'], true) ? bin2hex(random_bytes(16)) : null;
        $pkce = Pkce::generate();
        $_SESSION['reader_oauth'] = [
            'provider' => $providerName,
            'state' => $oauthState,
            'nonce' => $nonce,
            'pkce' => $pkce,
            'site_id' => $siteId,
        ];

        Http::redirect($provider->getAuthorizationUrl($oauthState, $nonce, $pkce));
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function callback(array $config, array $params = []): void
    {
        $siteId = (string) ($params['site_id'] ?? '');
        $providerName = strtolower((string) ($params['provider'] ?? ''));
        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowLogin($pdo, 'reader_callback', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $oauth = $_SESSION['reader_oauth'] ?? null;
        if (!is_array($oauth)
            || $siteId === ''
            || ($oauth['site_id'] ?? '') !== $siteId
            || ($oauth['provider'] ?? '') !== $providerName
        ) {
            $this->htmlFail(400, 'invalid_state', 'Reader login session missing or mismatch');

            return;
        }

        $returnedState = (string) ($_GET['state'] ?? '');
        if ($returnedState === '' || !hash_equals((string) $oauth['state'], $returnedState)) {
            $this->htmlFail(400, 'invalid_state', 'OAuth state mismatch');

            return;
        }

        $audit = new AuditLogger($pdo);
        $sites = new PublishedSiteRepository($pdo);
        $site = $sites->findBySiteId($siteId);
        if ($site === null || !$site->enabled) {
            $this->htmlFail(404, 'site_not_found', 'Unknown site');

            return;
        }

        try {
            $provider = (new ProviderFactory($config))->make($providerName);
            if (method_exists($provider, 'setExpectedNonce') && !empty($oauth['nonce'])) {
                $provider->setExpectedNonce((string) $oauth['nonce']);
            }
            $identity = $provider->handleCallback([
                'code' => (string) ($_GET['code'] ?? ''),
                'code_verifier' => (string) (($oauth['pkce']['code_verifier'] ?? '')),
                'nonce' => $oauth['nonce'] ?? null,
            ]);
            $user = (new UserProvisioner($pdo, [
                'app_env' => (string) $config['app_env'],
                'allowed_email_domains' => $config['allowed_email_domains'] ?? [],
            ]))->resolve($identity);

            if ($site->visibility === PublishedSite::VIS_PRIVATE) {
                if ($site->tenantId === null
                    || !(new TenantRepository($pdo))->isTenantMember($site->tenantId, $user->id)
                ) {
                    $audit->record(
                        action: 'reader.login.denied',
                        result: AuditLogger::RESULT_FAILURE,
                        actorType: AuditLogger::ACTOR_SUBJECT,
                        actorId: $user->id,
                        target: 'site:' . $siteId,
                        ip: Http::clientIp(),
                    );
                    unset($_SESSION['reader_oauth']);
                    $this->htmlFail(403, 'forbidden', 'Private site requires tenant membership');

                    return;
                }
            }

            $ttl = max(60, (int) ($config['session']['ttl_minutes'] ?? 480) * 60);
            $issued = (new ReaderSessionRepository($pdo))->issue(
                $user->id,
                $siteId,
                [ReaderSessionRepository::SCOPE_PUBLISH_READ],
                $ttl,
            );
            unset($_SESSION['reader_oauth']);
            // Do NOT set editor $_SESSION['user_id'] — reader cookie only (S7 isolation).
            $this->setReaderCookie($config, $issued['token'], $ttl);

            $audit->record(
                action: 'reader.login.success',
                result: AuditLogger::RESULT_SUCCESS,
                actorType: AuditLogger::ACTOR_SUBJECT,
                actorId: $user->id,
                target: 'site:' . $siteId,
                ip: Http::clientIp(),
            );

            header('Content-Type: text/html; charset=utf-8');
            echo '<!doctype html><html><head><meta charset="utf-8"><title>Reader signed in</title></head><body>';
            echo '<h1>Reader session established</h1>';
            echo '<p>Site <code>' . htmlspecialchars($siteId, ENT_QUOTES) . '</code> — scope <code>publish:read</code> only.</p>';
            echo '<p><a href="/site/' . rawurlencode($siteId) . '/session">Check session</a></p>';
            echo '</body></html>';
        } catch (\Throwable $e) {
            $audit->record(
                action: 'reader.login.failure',
                result: AuditLogger::RESULT_FAILURE,
                actorType: AuditLogger::ACTOR_SYSTEM,
                target: 'site:' . $siteId,
                ip: Http::clientIp(),
            );
            $this->htmlFail(400, 'login_failed', 'Reader login failed');
        }
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function session(array $config, array $params = []): void
    {
        $siteId = (string) ($params['site_id'] ?? '');
        $pdo = Connection::get($config['db']);
        $site = (new PublishedSiteRepository($pdo))->findBySiteId($siteId);
        if ($site === null || !$site->enabled) {
            Http::json(404, ['error' => 'site_not_found']);

            return;
        }

        if ($site->visibility === PublishedSite::VIS_PUBLIC) {
            Http::json(200, [
                'site_id' => $siteId,
                'visibility' => $site->visibility,
                'auth_required' => false,
                'scopes' => [],
            ]);

            return;
        }

        $token = $this->readReaderCookie($config);
        $session = $token !== ''
            ? (new ReaderSessionRepository($pdo))->findActiveByToken($token)
            : null;

        if ($session === null || $session['site_id'] !== $siteId) {
            $loginPath = '/site/' . rawurlencode($siteId) . '/login';
            // Browser navigations: 302 to the reader chooser (spec §9 anonymous redirect).
            // API / XHR clients keep JSON 401 + login hint (RP owns its own redirect UX).
            if ($this->wantsBrowserRedirect()) {
                Http::redirect($loginPath);

                return;
            }
            Http::json(401, [
                'error' => 'unauthenticated',
                'site_id' => $siteId,
                'visibility' => $site->visibility,
                'login' => $loginPath,
            ]);

            return;
        }

        Http::json(200, [
            'site_id' => $siteId,
            'visibility' => $site->visibility,
            'sub' => $session['user_id'],
            'scopes' => $session['scopes'],
            'token_use' => 'reader',
            'expires_at' => $session['expires_at'],
        ]);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function logout(array $config, array $params = []): void
    {
        $siteId = (string) ($params['site_id'] ?? '');
        $pdo = Connection::get($config['db']);
        $token = $this->readReaderCookie($config);
        if ($token !== '') {
            (new ReaderSessionRepository($pdo))->revokeByToken($token);
        }
        $this->clearReaderCookie($config);
        Http::json(200, ['ok' => true, 'site_id' => $siteId]);
    }

    /** @param array<string, mixed> $config */
    private function cookieName(array $config): string
    {
        return (string) ($config['session']['reader_cookie_name'] ?? 'GPSREADER');
    }

    /** @param array<string, mixed> $config */
    private function setReaderCookie(array $config, string $token, int $ttl): void
    {
        setcookie($this->cookieName($config), $token, [
            'expires' => time() + $ttl,
            'path' => '/',
            'secure' => (bool) ($config['session']['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @param array<string, mixed> $config */
    private function clearReaderCookie(array $config): void
    {
        setcookie($this->cookieName($config), '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => (bool) ($config['session']['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    /** @param array<string, mixed> $config */
    private function readReaderCookie(array $config): string
    {
        $name = $this->cookieName($config);

        return isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) ? $_COOKIE[$name] : '';
    }

    private function wantsBrowserRedirect(): bool
    {
        return Http::prefersHtml();
    }

    private function htmlFail(int $status, string $error, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('X-Error: ' . $error);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Reader login failed</title></head><body>';
        echo '<h1>Reader login failed</h1><p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
        echo '</body></html>';
    }
}
