<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class OAuthClient
{
    /**
     * @param list<string> $redirectUris
     */
    public function __construct(
        public readonly string $clientId,
        public readonly ?string $clientSecretHash,
        public readonly string $name,
        public readonly array $redirectUris,
        public readonly string $type,
        public readonly bool $enabled,
    ) {
    }

    public function isConfidential(): bool
    {
        return $this->type === 'confidential';
    }

    public function allowsRedirectUri(string $uri): bool
    {
        return in_array($uri, $this->redirectUris, true);
    }
}
