<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use GrandpaSSOn\Domain\TenantMembership;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use PDO;

/**
 * Builds additive session/exchange claims (spec §6.2) without dropping v0 fields.
 *
 * Active tenant selection (TODO(spec) for explicit active-tenant):
 * - 0 memberships → null / []
 * - 1 membership → that tenant
 * - many → lowest slug (stable order from TenantRepository)
 */
final class SessionClaimsResolver
{
    /** @var list<string> */
    public const DEFAULT_SCOPES = ['openid', 'profile', 'email', 'tenant:read'];

    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantRepository $tenants,
    ) {
    }

    /**
     * @param array{id: string, primary_email: string, display_name: string, status: string} $user
     * @return array{
     *   subject: array{id: string, email: string, name: string, idp: string|null},
     *   tenant: array{id: string, slug: string, role: string}|null,
     *   tenants: list<array{id: string, slug: string, role: string}>,
     *   groups: list<string>,
     *   scopes: list<string>
     * }
     */
    public function resolve(array $user): array
    {
        $memberships = $this->tenants->listMembershipsForUser($user['id']);
        $tenants = [];
        foreach ($memberships as $m) {
            $tenants[] = $this->tenantClaim($m);
        }

        $active = $memberships[0] ?? null;
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
