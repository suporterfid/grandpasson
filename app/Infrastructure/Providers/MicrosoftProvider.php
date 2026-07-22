<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;

final class MicrosoftProvider implements ProviderInterface
{
    private ?array $discovery = null;
    private ?string $expectedNonce = null;

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, tenant_id: string, scopes?: list<string>} $config
     * @param null|callable(string, string): AccessToken $tokenExchanger
     */
    public function __construct(
        private readonly array $config,
        private readonly ?DiscoveryClient $discoveryClient = null,
        private mixed $tokenExchanger = null,
        private readonly ?OidcIdTokenValidator $validator = null,
    ) {
        if ($this->tokenExchanger !== null && !is_callable($this->tokenExchanger)) {
            throw new \InvalidArgumentException('tokenExchanger must be callable');
        }
        $tenant = trim((string) ($config['tenant_id'] ?? ''));
        if ($tenant === '') {
            throw new ProviderException(
                'MS_TENANT_ID is required for Microsoft login (set a directory tenant ID, or "common" only when intentional)'
            );
        }
    }

    public function getName(): string
    {
        return 'microsoft';
    }

    public function tenantId(): string
    {
        return trim((string) $this->config['tenant_id']);
    }

    public function discoveryUrl(): string
    {
        return sprintf(
            'https://login.microsoftonline.com/%s/v2.0/.well-known/openid-configuration',
            rawurlencode($this->tenantId())
        );
    }

    public function getAuthorizationUrl(string $state, ?string $nonce, array $pkce): string
    {
        $this->expectedNonce = $nonce;
        $league = $this->leagueProvider();

        $options = [
            'state' => $state,
            'scope' => implode(' ', $this->config['scopes'] ?? ['openid', 'email', 'profile']),
            'code_challenge' => $pkce['code_challenge'],
            'code_challenge_method' => $pkce['code_challenge_method'],
        ];
        if ($nonce !== null && $nonce !== '') {
            $options['nonce'] = $nonce;
        }

        return $league->getAuthorizationUrl($options);
    }

    public function handleCallback(array $request): NormalizedIdentity
    {
        $code = (string) ($request['code'] ?? '');
        if ($code === '') {
            throw new ProviderException('Missing authorization code');
        }

        $verifier = (string) ($request['code_verifier'] ?? '');
        $token = $this->exchangeCode($code, $verifier);
        $idToken = (string) ($token->getValues()['id_token'] ?? '');
        $nonce = $this->expectedNonce ?? (isset($request['nonce']) ? (string) $request['nonce'] : null);
        $claims = $this->idTokenValidator()->validate($idToken, $nonce);

        $sub = (string) ($claims['sub'] ?? '');
        if ($sub === '') {
            throw new ProviderException('Microsoft ID token missing sub');
        }

        [$email, $verified] = $this->resolveEmail($claims);
        $name = isset($claims['name']) ? (string) $claims['name'] : null;

        return new NormalizedIdentity(
            provider: 'microsoft',
            subject: $sub,
            email: $email,
            emailVerified: $verified,
            name: $name,
            avatarUrl: null,
            username: isset($claims['preferred_username']) ? (string) $claims['preferred_username'] : null,
            rawClaims: $claims,
        );
    }

    public function setExpectedNonce(?string $nonce): void
    {
        $this->expectedNonce = $nonce;
    }

    /**
     * Prefer verified email claim; never treat raw UPN / preferred_username as verified.
     *
     * @param array<string, mixed> $claims
     * @return array{0: ?string, 1: bool}
     */
    public static function resolveEmail(array $claims): array
    {
        if (isset($claims['email']) && is_string($claims['email']) && $claims['email'] !== '') {
            $verified = false;
            if (array_key_exists('email_verified', $claims)) {
                $verified = filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN);
            } elseif (array_key_exists('xms_edov', $claims)) {
                // Entra "email domain owner verified" extension when present.
                $verified = filter_var($claims['xms_edov'], FILTER_VALIDATE_BOOLEAN);
            }

            return [$claims['email'], $verified];
        }

        // UPN / preferred_username alone is never treated as verified email for auto-link.
        $upn = null;
        if (isset($claims['preferred_username']) && is_string($claims['preferred_username'])) {
            $upn = $claims['preferred_username'];
        } elseif (isset($claims['upn']) && is_string($claims['upn'])) {
            $upn = $claims['upn'];
        }

        return [$upn, false];
    }

    private function exchangeCode(string $code, string $verifier): AccessToken
    {
        if ($this->tokenExchanger !== null) {
            return ($this->tokenExchanger)($code, $verifier);
        }

        $params = ['code' => $code];
        if ($verifier !== '') {
            $params['code_verifier'] = $verifier;
        }

        return $this->leagueProvider()->getAccessToken('authorization_code', $params);
    }

    private function leagueProvider(): GenericProvider
    {
        $disc = $this->discovery();

        return new GenericProvider([
            'clientId' => $this->config['client_id'],
            'clientSecret' => $this->config['client_secret'],
            'redirectUri' => $this->config['redirect_uri'],
            'urlAuthorize' => (string) $disc['authorization_endpoint'],
            'urlAccessToken' => (string) $disc['token_endpoint'],
            'urlResourceOwnerDetails' => '',
        ]);
    }

    private function idTokenValidator(): OidcIdTokenValidator
    {
        if ($this->validator !== null) {
            return $this->validator;
        }

        $discoveryClient = $this->discoveryClient ?? new DiscoveryClient();
        $disc = $this->discovery();
        $jwksUri = (string) ($disc['jwks_uri'] ?? '');
        $issuer = (string) ($disc['issuer'] ?? '');
        if ($jwksUri === '' || $issuer === '') {
            throw new ProviderException('Microsoft discovery missing issuer/jwks_uri');
        }

        return new OidcIdTokenValidator(
            [$issuer],
            $this->config['client_id'],
            static function () use ($discoveryClient, $jwksUri): array {
                return $discoveryClient->fetchJson($jwksUri);
            },
        );
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        if ($this->discovery === null) {
            $client = $this->discoveryClient ?? new DiscoveryClient();
            $this->discovery = $client->fetchJson($this->discoveryUrl());
        }

        return $this->discovery;
    }
}
