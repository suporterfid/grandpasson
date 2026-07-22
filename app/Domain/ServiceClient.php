<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class ServiceClient
{
    /**
     * @param list<string> $allowedScopes
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $clientSecretHash,
        public readonly string $name,
        public readonly array $allowedScopes,
        public readonly ?string $defaultAudience,
        public readonly bool $enabled,
    ) {
    }

    public function allowsScope(string $scope): bool
    {
        return in_array($scope, $this->allowedScopes, true);
    }

    /**
     * @param list<string> $requested
     */
    public function allowsAllScopes(array $requested): bool
    {
        foreach ($requested as $scope) {
            if (!$this->allowsScope($scope)) {
                return false;
            }
        }

        return true;
    }
}
