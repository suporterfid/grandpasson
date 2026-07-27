<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

final class ProviderFactory
{
    /**
     * @param array{
     *   providers: array<string, array{
     *     client_id: string,
     *     client_secret: string,
     *     redirect_uri: string,
     *     scopes?: list<string>,
     *     tenant_id?: string
     *   }>
     * } $config
     */
    public function __construct(
        private readonly array $config,
    ) {
    }

    public function make(string $provider): ProviderInterface
    {
        $providers = $this->config['providers'] ?? [];
        if (!isset($providers[$provider]) || !is_array($providers[$provider])) {
            throw new ProviderException('Unknown provider: ' . $provider);
        }

        $cfg = $providers[$provider];
        if (($cfg['client_id'] ?? '') === '' || ($cfg['client_secret'] ?? '') === '') {
            throw new ProviderException('Provider credentials are not configured: ' . $provider);
        }

        return match ($provider) {
            'google' => new GoogleProvider($cfg),
            'microsoft' => new MicrosoftProvider([
                'client_id' => $cfg['client_id'],
                'client_secret' => $cfg['client_secret'],
                'redirect_uri' => $cfg['redirect_uri'],
                'scopes' => $cfg['scopes'] ?? ['openid', 'email', 'profile'],
                'tenant_id' => (string) ($cfg['tenant_id'] ?? ''),
            ]),
            'github' => new GithubProvider($cfg),
            default => throw new ProviderException('Unknown provider: ' . $provider),
        };
    }
}
