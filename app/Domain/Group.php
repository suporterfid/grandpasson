<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class Group
{
    public function __construct(
        public readonly string $id,
        public readonly string $tenantId,
        public readonly string $slug,
        public readonly string $name,
    ) {
    }
}
