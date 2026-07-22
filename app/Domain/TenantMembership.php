<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class TenantMembership
{
    public function __construct(
        public readonly string $tenantId,
        public readonly string $userId,
        public readonly string $role,
        public readonly string $tenantSlug = '',
        public readonly string $tenantName = '',
    ) {
        if (!in_array($role, Tenant::ROLES, true)) {
            throw new \InvalidArgumentException('Invalid tenant role: ' . $role);
        }
    }
}
