<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

/**
 * Validates OIDC ID tokens (iss, aud, exp, signature, optional nonce).
 */
final class OidcIdTokenValidator
{
    /** @var array<string, array<string, Key>> */
    private array $jwksCache = [];

    /**
     * @param list<string> $expectedIssuers
     * @param null|callable(string): array{keys: list<array<string, mixed>>} $jwksFetcher
     * @param array<string, Key>|null $staticKeys For tests — skips JWKS fetch when set
     */
    public function __construct(
        private readonly array $expectedIssuers,
        private readonly string $expectedAudience,
        private mixed $jwksFetcher = null,
        private readonly ?array $staticKeys = null,
    ) {
        if ($this->jwksFetcher !== null && !is_callable($this->jwksFetcher)) {
            throw new \InvalidArgumentException('jwksFetcher must be callable');
        }
    }

    /**
     * @return array<string, mixed> Validated claims
     */
    public function validate(string $idToken, ?string $expectedNonce = null): array
    {
        if ($idToken === '') {
            throw new ProviderException('Missing ID token');
        }

        $keys = $this->staticKeys ?? $this->keysForToken($idToken);

        try {
            $claims = (array) JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            throw new ProviderException('ID token validation failed: ' . $e->getMessage(), 0, $e);
        }

        $iss = (string) ($claims['iss'] ?? '');
        if (!in_array($iss, $this->expectedIssuers, true)) {
            throw new ProviderException('ID token issuer mismatch');
        }

        $aud = $claims['aud'] ?? null;
        $audiences = is_array($aud) ? $aud : [(string) $aud];
        if (!in_array($this->expectedAudience, $audiences, true)) {
            throw new ProviderException('ID token audience mismatch');
        }

        if ($expectedNonce !== null) {
            $nonce = (string) ($claims['nonce'] ?? '');
            if ($nonce === '' || !hash_equals($expectedNonce, $nonce)) {
                throw new ProviderException('ID token nonce mismatch');
            }
        }

        return $claims;
    }

    /**
     * @return array<string, Key>
     */
    private function keysForToken(string $idToken): array
    {
        $parts = explode('.', $idToken);
        if (count($parts) < 2) {
            throw new ProviderException('Malformed ID token');
        }

        $headerJson = JWT::urlsafeB64Decode($parts[0]);
        $header = json_decode($headerJson, true);
        if (!is_array($header)) {
            throw new ProviderException('Malformed ID token header');
        }

        $jwksUri = (string) ($header['jku'] ?? '');
        // Prefer configured fetcher keyed by issuer discovery; header jku rarely present.
        $fetcher = $this->jwksFetcher;
        if ($fetcher === null) {
            throw new ProviderException('No JWKS fetcher configured for ID token validation');
        }

        $cacheKey = $jwksUri !== '' ? $jwksUri : 'default';
        if (!isset($this->jwksCache[$cacheKey])) {
            $jwks = $fetcher($cacheKey);
            if (!isset($jwks['keys']) || !is_array($jwks['keys'])) {
                throw new ProviderException('Invalid JWKS payload');
            }
            $this->jwksCache[$cacheKey] = JWK::parseKeySet($jwks);
        }

        return $this->jwksCache[$cacheKey];
    }
}
