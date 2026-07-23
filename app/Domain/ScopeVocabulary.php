<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

/**
 * Broker scope vocabulary (extension §6.3).
 * Workspace narrowing uses `aud` (e.g. workspace/<id>), not per-workspace scopes.
 */
final class ScopeVocabulary
{
    public const OPENID = 'openid';
    public const PROFILE = 'profile';
    public const EMAIL = 'email';
    public const TENANT_READ = 'tenant:read';
    public const KB_READ = 'kb:read';
    public const KB_WRITE = 'kb:write';
    public const PUBLISH_READ = 'publish:read';
    public const TASKS_CALLBACK = 'tasks:callback';
    /** Inbound TaskConnect task submission (aud must cover workspace/environment public id). */
    public const TASKS_WRITE = 'tasks:write';

    /**
     * Canonical known scopes (docs + admin validation allowlist).
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::OPENID,
            self::PROFILE,
            self::EMAIL,
            self::TENANT_READ,
            self::KB_READ,
            self::KB_WRITE,
            self::PUBLISH_READ,
            self::TASKS_CALLBACK,
            self::TASKS_WRITE,
        ];
    }

    /**
     * Scopes typically granted to service clients / machine tokens.
     *
     * @return list<string>
     */
    public static function machineScopes(): array
    {
        return [
            self::TENANT_READ,
            self::KB_READ,
            self::KB_WRITE,
            self::PUBLISH_READ,
            self::TASKS_CALLBACK,
            self::TASKS_WRITE,
        ];
    }

    public static function isKnown(string $scope): bool
    {
        return in_array($scope, self::all(), true);
    }

    /**
     * @param list<string> $scopes
     * @return list<string> Unknown scopes
     */
    public static function unknown(array $scopes): array
    {
        $unknown = [];
        foreach ($scopes as $scope) {
            if (!self::isKnown($scope)) {
                $unknown[] = $scope;
            }
        }

        return $unknown;
    }

    /**
     * Scopes a subject may self-issue onto a PAT (R10). Excludes scopes §6.3
     * reserves for trusted services (`kb:write`, `tasks:callback`, `tasks:write`).
     * Admin CLI `pat:create` is unaffected — it validates against all() for break-glass.
     *
     * @return list<string>
     */
    public static function selfServiceScopes(): array
    {
        return array_values(array_diff(self::all(), [
            self::KB_WRITE,
            self::TASKS_CALLBACK,
            self::TASKS_WRITE,
        ]));
    }

    /**
     * @param list<string> $scopes
     * @return list<string> Scopes not allowed for self-service PAT issuance (unknown or reserved)
     */
    public static function disallowedForSelfService(array $scopes): array
    {
        $allowed = self::selfServiceScopes();
        $disallowed = [];
        foreach ($scopes as $scope) {
            if (!in_array($scope, $allowed, true)) {
                $disallowed[] = $scope;
            }
        }

        return $disallowed;
    }
}
