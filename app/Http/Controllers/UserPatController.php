<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Domain\AccessToken;
use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

/**
 * User self-service PATs (R10) — subject-authenticated via AUTHSESSID.
 * Admin CLI /admin remains available for break-glass.
 */
final class UserPatController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function list(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_pats', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $tokens = [];
        foreach ((new AccessTokenRepository($pdo))->listActive(null, $userId, AccessToken::KIND_PAT) as $t) {
            $tokens[] = $this->publicRow($t);
        }

        Http::json(200, [
            'tokens' => $tokens,
            'count' => count($tokens),
            'csrf' => Csrf::token(),
        ]);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function create(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_pats_write', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        if (!$this->requireCsrf($body)) {
            return;
        }

        $scopesRaw = $body['scopes'] ?? ($body['scope'] ?? '');
        if (is_array($scopesRaw)) {
            $scopes = array_values(array_filter(array_map(
                static fn ($s): string => trim((string) $s),
                $scopesRaw
            )));
        } else {
            $scopes = array_values(array_filter(array_map(
                'trim',
                explode(',', str_replace(' ', ',', (string) $scopesRaw))
            )));
        }
        if ($scopes === []) {
            Http::json(400, ['error' => 'invalid_request', 'message' => 'scopes is required']);

            return;
        }

        $ttlDays = (int) ($body['ttl_days'] ?? $body['ttl-days'] ?? 365);
        if ($ttlDays < 1 || $ttlDays > 3650) {
            Http::json(400, ['error' => 'invalid_request', 'message' => 'ttl_days must be 1..3650']);

            return;
        }

        $aud = isset($body['aud']) && is_scalar($body['aud']) && (string) $body['aud'] !== ''
            ? (string) $body['aud']
            : null;
        $label = isset($body['label']) && is_scalar($body['label']) && (string) $body['label'] !== ''
            ? (string) $body['label']
            : null;

        $issued = (new AccessTokenRepository($pdo))->issuePat(
            $userId,
            implode(' ', $scopes),
            $aud,
            $ttlDays * 86400,
            $label,
        );
        (new AuditLogger($pdo))->record(
            'pat.create',
            AuditLogger::RESULT_SUCCESS,
            AuditLogger::ACTOR_SUBJECT,
            $userId,
            $issued['record']->id,
            null,
            Http::clientIp(),
        );

        Http::json(201, [
            'ok' => true,
            'token_id' => $issued['record']->id,
            'kind' => AccessToken::KIND_PAT,
            'subject_user_id' => $userId,
            'scope' => $issued['record']->scope,
            'aud' => $issued['record']->aud,
            'label' => $issued['record']->label,
            'expires_at' => $issued['record']->expiresAt,
            'expires_in' => $issued['expires_in'],
            'token' => $issued['token'],
            'csrf' => Csrf::token(),
        ]);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function revoke(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_pats_write', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        if (!$this->requireCsrf($body)) {
            return;
        }

        $tokenId = (string) ($params['id'] ?? '');
        if ($tokenId === '') {
            Http::json(400, ['error' => 'invalid_request', 'message' => 'token id required']);

            return;
        }

        $tokens = new AccessTokenRepository($pdo);
        $existing = $tokens->findById($tokenId);
        if ($existing === null || !$existing->isPat() || $existing->subjectUserId !== $userId) {
            Http::json(404, ['error' => 'not_found', 'message' => 'PAT not found']);

            return;
        }
        if ($existing->revokedAt !== null) {
            Http::json(200, ['ok' => true, 'revoked' => 0, 'token_id' => $tokenId]);

            return;
        }

        $count = $tokens->revokeById($tokenId);
        (new AuditLogger($pdo))->record(
            'pat.revoke',
            AuditLogger::RESULT_SUCCESS,
            AuditLogger::ACTOR_SUBJECT,
            $userId,
            $tokenId,
            null,
            Http::clientIp(),
        );

        Http::json(200, ['ok' => true, 'revoked' => $count, 'token_id' => $tokenId, 'csrf' => Csrf::token()]);
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

    /** @param array<string, mixed> $body */
    private function requireCsrf(array $body): bool
    {
        $provided = null;
        if (isset($body['csrf']) && is_scalar($body['csrf'])) {
            $provided = (string) $body['csrf'];
        }
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (($provided === null || $provided === '') && is_string($header) && $header !== '') {
            $provided = $header;
        }
        if (!Csrf::validate($provided)) {
            Http::json(403, ['error' => 'invalid_csrf']);

            return false;
        }

        return true;
    }

    /** @return array<string, mixed> */
    private function publicRow(AccessToken $t): array
    {
        return [
            'id' => $t->id,
            'label' => $t->label,
            'scope' => $t->scope,
            'aud' => $t->aud,
            'expires_at' => $t->expiresAt,
            'created_at' => $t->createdAt,
            'last_used_at' => $t->lastUsedAt,
        ];
    }
}
