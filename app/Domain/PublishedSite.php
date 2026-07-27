<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class PublishedSite
{
    public const VIS_PUBLIC = 'public';
    public const VIS_AUTHENTICATED = 'authenticated';
    public const VIS_PRIVATE = 'private';

    public const VISIBILITIES = [self::VIS_PUBLIC, self::VIS_AUTHENTICATED, self::VIS_PRIVATE];

    public function __construct(
        public readonly string $siteId,
        public readonly string $name,
        public readonly string $visibility,
        public readonly ?string $tenantId,
        public readonly bool $enabled,
        public readonly string $createdAt,
    ) {
    }
}
