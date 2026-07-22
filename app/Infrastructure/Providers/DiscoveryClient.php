<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

/**
 * Fetches OIDC discovery + JWKS over HTTP (file_get_contents / streams).
 */
class DiscoveryClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchJson(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $this->timeoutSeconds,
                'header' => "Accept: application/json\r\nUser-Agent: GrandpaSSOn/0.1\r\n",
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new ProviderException('Failed to fetch: ' . $url);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new ProviderException('Invalid JSON from: ' . $url);
        }

        return $decoded;
    }
}
