<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Auth\SessionClaimsResolver;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use GrandpaSSOn\Infrastructure\Db\UserActiveTenantRepository;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

/** Sticky active-tenant selection for authenticated subjects (R2). */
final class ActiveTenantController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function show(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_active_tenant', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $stmt = $pdo->prepare(
            'SELECT id, primary_email, display_name, status FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            Http::json(401, ['error' => 'unauthenticated']);

            return;
        }

        $claims = (new SessionClaimsResolver($pdo, new TenantRepository($pdo)))->resolve([
            'id' => (string) $row['id'],
            'primary_email' => (string) $row['primary_email'],
            'display_name' => (string) $row['display_name'],
            'status' => (string) $row['status'],
        ]);

        Http::json(200, [
            'tenant' => $claims['tenant'],
            'tenants' => $claims['tenants'],
            'groups' => $claims['groups'],
            'csrf' => Csrf::token(),
        ]);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function set(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_active_tenant_write', $config)) {
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

        $hint = trim((string) ($body['tenant'] ?? ''));
        if ($hint === '') {
            Http::json(400, ['error' => 'invalid_request', 'message' => 'tenant (id or slug) is required']);

            return;
        }

        $tenants = new TenantRepository($pdo);
        $memberships = $tenants->listMembershipsForUser($userId);
        $match = null;
        foreach ($memberships as $m) {
            if ($m->tenantId === $hint || $m->tenantSlug === $hint) {
                $match = $m;
                break;
            }
        }
        if ($match === null) {
            Http::json(403, ['error' => 'forbidden', 'message' => 'Not a member of that tenant']);

            return;
        }

        (new UserActiveTenantRepository($pdo))->set($userId, $match->tenantId);
        (new AuditLogger($pdo))->record(
            'tenant.active.set',
            AuditLogger::RESULT_SUCCESS,
            AuditLogger::ACTOR_SUBJECT,
            $userId,
            $match->tenantId,
            null,
            Http::clientIp(),
        );

        $stmt = $pdo->prepare(
            'SELECT id, primary_email, display_name, status FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $claims = (new SessionClaimsResolver($pdo, $tenants))->resolve([
            'id' => (string) $row['id'],
            'primary_email' => (string) $row['primary_email'],
            'display_name' => (string) $row['display_name'],
            'status' => (string) $row['status'],
        ]);

        Http::json(200, [
            'ok' => true,
            'tenant' => $claims['tenant'],
            'tenants' => $claims['tenants'],
            'groups' => $claims['groups'],
            'csrf' => Csrf::token(),
        ]);
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
