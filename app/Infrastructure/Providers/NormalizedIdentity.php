<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

/**
 * Provider-normalized identity used by provisioning / linking (T-provision).
 */
final class NormalizedIdentity
{
    public function __construct(
        public readonly string $provider,
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
        public readonly ?string $name,
        public readonly ?string $avatarUrl = null,
        public readonly ?string $username = null,
        /** @var array<string, mixed> */
        public readonly array $rawClaims = [],
    ) {
        if ($provider === '') {
            throw new \InvalidArgumentException('provider is required');
        }
        if ($subject === '') {
            throw new \InvalidArgumentException('subject is required');
        }
    }
}
