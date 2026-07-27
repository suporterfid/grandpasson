<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class User
{
    public function __construct(
        public readonly string $id,
        public readonly string $primaryEmail,
        public readonly bool $emailVerified,
        public readonly string $displayName,
        public readonly ?string $avatarUrl,
        public readonly string $status,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
