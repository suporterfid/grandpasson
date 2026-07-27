<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class JwtSigningKey
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RETIRING = 'retiring';
    public const STATUS_RETIRED = 'retired';

    public function __construct(
        public readonly string $kid,
        public readonly string $alg,
        public readonly string $publicPem,
        public readonly string $privatePem,
        public readonly string $status,
        public readonly string $createdAt,
        public readonly ?string $retiredAt,
    ) {
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
