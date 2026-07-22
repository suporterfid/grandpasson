<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class AccessToken
{
    public function __construct(
        public readonly string $id,
        public readonly string $tokenHash,
        public readonly string $clientId,
        public readonly ?string $subjectUserId,
        public readonly string $scope,
        public readonly ?string $aud,
        public readonly ?string $tenantId,
        public readonly string $expiresAt,
        public readonly ?string $revokedAt,
        public readonly string $createdAt,
        public readonly ?string $lastUsedAt,
    ) {
    }

    public function isActive(?string $nowUtc = null): bool
    {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');

        return $this->revokedAt === null && $this->expiresAt > $now;
    }
}
