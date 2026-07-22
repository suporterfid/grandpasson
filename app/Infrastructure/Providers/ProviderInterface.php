<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

interface ProviderInterface
{
    public function getName(): string;

    /**
     * @param array{code_verifier: string, code_challenge: string, code_challenge_method: string} $pkce
     */
    public function getAuthorizationUrl(string $state, ?string $nonce, array $pkce): string;

    /**
     * @param array<string, mixed> $request Callback query/body parameters (must include code; state verified by caller)
     */
    public function handleCallback(array $request): NormalizedIdentity;
}
