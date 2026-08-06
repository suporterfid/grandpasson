<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Domain\Locale;
use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\UserLocaleRepository;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

/** Central language preference for authenticated subjects (i18n foundation). */
final class LocaleController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function show(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_locale', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $locale = (new UserLocaleRepository($pdo))->get($userId);

        Http::json(200, ['locale' => $locale, 'csrf' => Csrf::token()]);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function set(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_locale_write', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        $csrf = isset($body['csrf']) && is_scalar($body['csrf']) ? (string) $body['csrf'] : null;
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (($csrf === null || $csrf === '') && is_string($header) && $header !== '') {
            $csrf = $header;
        }
        if (!Csrf::validate($csrf)) {
            Http::json(403, ['error' => 'invalid_csrf']);

            return;
        }

        $locale = trim((string) ($body['locale'] ?? ''));
        if (!Locale::isSupported($locale)) {
            Http::json(400, [
                'error' => 'invalid_request',
                'message' => 'locale must be one of: ' . implode(', ', Locale::SUPPORTED),
            ]);

            return;
        }

        (new UserLocaleRepository($pdo))->set($userId, $locale);
        (new AuditLogger($pdo))->record(
            'locale.set',
            AuditLogger::RESULT_SUCCESS,
            AuditLogger::ACTOR_SUBJECT,
            $userId,
            $locale,
            null,
            Http::clientIp(),
        );

        Http::json(200, ['ok' => true, 'locale' => $locale, 'csrf' => Csrf::token()]);
    }

    private function requireSubject(): ?string
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_string($userId) || $userId === '') {
            Http::json(401, ['error' => 'unauthenticated']);

            return null;
        }

        return $userId;
    }
}
