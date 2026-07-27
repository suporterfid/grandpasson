<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Audit;

use PDO;

/**
 * Dual-writes v0 audit_events and v1 audit_log (extension §8).
 * Never stores tokens, secrets, or plaintext IPs.
 */
final class AuditLogger
{
    public const ACTOR_SUBJECT = 'subject';
    public const ACTOR_SERVICE = 'service';
    public const ACTOR_ADMIN = 'admin';
    public const ACTOR_SYSTEM = 'system';

    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILURE = 'failure';

    /** @var list<string> */
    private const ACTOR_TYPES = [
        self::ACTOR_SUBJECT,
        self::ACTOR_SERVICE,
        self::ACTOR_ADMIN,
        self::ACTOR_SYSTEM,
    ];

    /** @var list<string> */
    private const RESULTS = [self::RESULT_SUCCESS, self::RESULT_FAILURE];

    private const LEGACY_PROVIDER_MAX = 50;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Legacy v0 entry point. Dual-writes to audit_events and audit_log.
     */
    public function log(string $eventType, ?string $userId = null, ?string $provider = null, ?string $ip = null): void
    {
        $userAgent = $this->normalizeUserAgent($this->currentUserAgent());
        $this->assertNoSecrets($eventType, $userId, $provider, $userAgent);

        $ipHash = $this->hashIp($ip);
        $now = gmdate('Y-m-d H:i:s');
        $result = $this->inferResult($eventType);
        $actorType = $userId !== null && $userId !== '' ? self::ACTOR_SUBJECT : self::ACTOR_SYSTEM;

        $this->dualWrite(
            eventType: $eventType,
            legacyUserId: $userId,
            legacyProvider: $provider,
            actorType: $actorType,
            actorId: $userId,
            action: $eventType,
            target: $provider,
            clientId: null,
            ipHash: $ipHash,
            userAgent: $userAgent,
            result: $result,
            createdAt: $now,
        );
    }

    /**
     * v1 security audit entry (extension §8 shape) into audit_log.
     * Also mirrors a compatible row into audit_events for gradual migration.
     */
    public function record(
        string $action,
        string $result,
        string $actorType = self::ACTOR_SYSTEM,
        ?string $actorId = null,
        ?string $target = null,
        ?string $clientId = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): void {
        if (!in_array($actorType, self::ACTOR_TYPES, true)) {
            throw new \InvalidArgumentException('Invalid actor_type: ' . $actorType);
        }
        if (!in_array($result, self::RESULTS, true)) {
            throw new \InvalidArgumentException('Invalid result: ' . $result);
        }
        if ($action === '') {
            throw new \InvalidArgumentException('action is required');
        }

        $ua = $this->normalizeUserAgent($userAgent ?? $this->currentUserAgent());
        $this->assertNoSecrets($action, $actorId, $target, $clientId, $ua);

        $ipHash = $this->hashIp($ip);
        $now = gmdate('Y-m-d H:i:s');
        $legacyUserId = $actorType === self::ACTOR_SUBJECT ? $actorId : null;

        $this->dualWrite(
            eventType: $action,
            legacyUserId: $legacyUserId,
            legacyProvider: $target,
            actorType: $actorType,
            actorId: $actorId,
            action: $action,
            target: $target,
            clientId: $clientId,
            ipHash: $ipHash,
            userAgent: $ua,
            result: $result,
            createdAt: $now,
        );
    }

    private function dualWrite(
        string $eventType,
        ?string $legacyUserId,
        ?string $legacyProvider,
        string $actorType,
        ?string $actorId,
        string $action,
        ?string $target,
        ?string $clientId,
        ?string $ipHash,
        ?string $userAgent,
        string $result,
        string $createdAt,
    ): void {
        $startedTx = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $legacy = $this->pdo->prepare(
                'INSERT INTO audit_events (user_id, event_type, provider, ip_hash, created_at)
                 VALUES (:user_id, :event_type, :provider, :ip_hash, :created_at)'
            );
            $legacy->execute([
                'user_id' => $legacyUserId,
                'event_type' => $eventType,
                'provider' => $this->truncate($legacyProvider, self::LEGACY_PROVIDER_MAX),
                'ip_hash' => $ipHash,
                'created_at' => $createdAt,
            ]);

            $rich = $this->pdo->prepare(
                'INSERT INTO audit_log
                 (actor_type, actor_id, action, target, client_id, ip_hash, user_agent, result, created_at)
                 VALUES
                 (:actor_type, :actor_id, :action, :target, :client_id, :ip_hash, :user_agent, :result, :created_at)'
            );
            $rich->execute([
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'target' => $target,
                'client_id' => $clientId,
                'ip_hash' => $ipHash,
                'user_agent' => $userAgent,
                'result' => $result,
                'created_at' => $createdAt,
            ]);

            if ($startedTx) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    private function inferResult(string $eventType): string
    {
        $lower = strtolower($eventType);
        if (
            str_contains($lower, 'fail')
            || str_contains($lower, 'denied')
            || str_contains($lower, 'disabled')
            || str_contains($lower, 'invalid')
        ) {
            return self::RESULT_FAILURE;
        }

        return self::RESULT_SUCCESS;
    }

    private function hashIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        return hash('sha256', $ip);
    }

    private function currentUserAgent(): ?string
    {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($ua) && $ua !== '' ? $ua : null;
    }

    private function normalizeUserAgent(?string $userAgent): ?string
    {
        if ($userAgent === null || $userAgent === '') {
            return null;
        }

        if (strlen($userAgent) > 512) {
            return substr($userAgent, 0, 512);
        }

        return $userAgent;
    }

    private function truncate(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (strlen($value) <= $max) {
            return $value;
        }

        return substr($value, 0, $max);
    }

    private function assertNoSecrets(?string ...$values): void
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $lower = strtolower($value);
            if (
                str_contains($lower, 'gpat_')
                || str_contains($lower, 'bearer ')
                || preg_match('/\b(client_secret|access_token|refresh_token)\b/', $lower) === 1
            ) {
                throw new \InvalidArgumentException('Refusing to store secret-like value in audit log');
            }
        }
    }
}
