<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use GrandpaSSOn\Domain\Tenant;
use GrandpaSSOn\Domain\TenantMembership;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use GrandpaSSOn\Infrastructure\Db\UserActiveTenantRepository;
use PDO;

/**
 * Builds additive session/exchange claims (spec §6.2) without dropping v0 fields.
 *
 * Active tenant selection (R2), in order:
 * 1. Explicit hint (tenant id or slug) when the subject is a member
 * 2. Sticky preference in `user_active_tenant` when still a member
 * 3. Highest role among memberships (owner > admin > member), then lowest slug
 * 4. None → null / []
 */
final class SessionClaimsResolver
{
    /** @var list<string> */
    public const DEFAULT_SCOPES = ['openid', 'profile', 'email', 'tenant:read'];

    /** @var array<string, int> */
    private const ROLE_RANK = [
        Tenant::ROLE_OWNER => 3,
        Tenant::ROLE_ADMIN => 2,
        Tenant::ROLE_MEMBER => 1,
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantRepository $tenants,
        private readonly ?UserActiveTenantRepository $activeTenants = null,
    ) {
    }

    /**
     * @param array{id: string, primary_email: string, display_name: string, status: string} $user
     * @param string|null $tenantHint Tenant id or slug from the RP (exchange body / query)
     * @param bool $persistHint When true and hint resolves, store as sticky preference
     * @return array{
     *   subject: array{id: string, email: string, name: string, idp: string|null},
     *   tenant: array{id: string, slug: string, role: string}|null,
     *   tenants: list<array{id: string, slug: string, role: string}>,
     *   groups: list<string>,
     *   scopes: list<string>
     * }
     */
    public function resolve(array $user, ?string $tenantHint = null, bool $persistHint = false): array
    {
        $memberships = $this->tenants->listMembershipsForUser($user['id']);
        $tenants = [];
        foreach ($memberships as $m) {
            $tenants[] = $this->tenantClaim($m);
        }

        $active = $this->selectActive($user['id'], $memberships, $tenantHint, $persistHint);
        $groups = [];
        if ($active !== null) {
            $groups = $this->tenants->listGroupSlugsForUserInTenant($active->tenantId, $user['id']);
        }

        return [
            'subject' => [
                'id' => $user['id'],
                'email' => $user['primary_email'],
                'name' => $user['display_name'],
                'idp' => $this->resolveIdp($user['id']),
            ],
            'tenant' => $active === null ? null : $this->tenantClaim($active),
            'tenants' => $tenants,
            'groups' => $groups,
            'scopes' => self::DEFAULT_SCOPES,
        ];
    }

    /**
     * @param list<TenantMembership> $memberships
     */
    private function selectActive(
        string $userId,
        array $memberships,
        ?string $tenantHint,
        bool $persistHint,
    ): ?TenantMembership {
        if ($memberships === []) {
            return null;
        }

        $hint = $tenantHint !== null ? trim($tenantHint) : '';
        if ($hint !== '') {
            $fromHint = $this->findMembership($memberships, $hint);
            if ($fromHint !== null) {
                if ($persistHint) {
                    $this->prefs()->set($userId, $fromHint->tenantId);
                }

                return $fromHint;
            }
        }

        $stickyId = $this->prefs()->getTenantId($userId);
        if ($stickyId !== null) {
            $fromSticky = $this->findMembership($memberships, $stickyId);
            if ($fromSticky !== null) {
                return $fromSticky;
            }
            // Stale preference (left tenant) — drop it.
            $this->prefs()->clear($userId);
        }

        return $this->preferByRoleThenSlug($memberships);
    }

    /**
     * @param list<TenantMembership> $memberships
     */
    private function findMembership(array $memberships, string $idOrSlug): ?TenantMembership
    {
        foreach ($memberships as $m) {
            if ($m->tenantId === $idOrSlug || $m->tenantSlug === $idOrSlug) {
                return $m;
            }
        }

        return null;
    }

    /**
     * @param list<TenantMembership> $memberships
     */
    private function preferByRoleThenSlug(array $memberships): TenantMembership
    {
        $best = $memberships[0];
        $bestRank = self::ROLE_RANK[$best->role] ?? 0;
        foreach ($memberships as $m) {
            $rank = self::ROLE_RANK[$m->role] ?? 0;
            if ($rank > $bestRank) {
                $best = $m;
                $bestRank = $rank;
                continue;
            }
            if ($rank === $bestRank && strcmp($m->tenantSlug, $best->tenantSlug) < 0) {
                $best = $m;
            }
        }

        return $best;
    }

    private function prefs(): UserActiveTenantRepository
    {
        return $this->activeTenants ?? new UserActiveTenantRepository($this->pdo);
    }

    /** @return array{id: string, slug: string, role: string} */
    private function tenantClaim(TenantMembership $m): array
    {
        return [
            'id' => $m->tenantId,
            'slug' => $m->tenantSlug,
            'role' => $m->role,
        ];
    }

    private function resolveIdp(string $userId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT provider FROM linked_identities
             WHERE user_id = :user_id
             ORDER BY last_login_at DESC, linked_at DESC
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $provider = $stmt->fetchColumn();

        return $provider === false ? null : (string) $provider;
    }
}
