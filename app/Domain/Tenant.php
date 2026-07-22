<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class Tenant
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_DISABLED = 'disabled';

    public const ROLE_OWNER = 'owner';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MEMBER = 'member';

    /** @var list<string> */
    public const ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN, self::ROLE_MEMBER];

    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $name,
        public readonly string $status,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
