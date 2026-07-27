<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Token\AccessToken;

final class GoogleProvider implements ProviderInterface
{
    private const DISCOVERY = 'https://accounts.google.com/.well-known/openid-configuration';
    private const ISSUERS = [
        'https://accounts.google.com',
        'accounts.google.com',
    ];

    private ?array $discovery = null;

    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, scopes?: list<string>} $config
     * @param null|callable(string, string): AccessToken $tokenExchanger code, verifier -> token (tests)
     */
    public function __construct(
        private readonly array $config,
        private readonly ?DiscoveryClient $discoveryClient = null,
        private mixed $tokenExchanger = null,
        private readonly ?OidcIdTokenValidator $validator = null,
        private ?string $expectedNonce = null,
    ) {
        if ($this->tokenExchanger !== null && !is_callable($this->tokenExchanger)) {
            throw new \InvalidArgumentException('tokenExchanger must be callable');
        }
    }

    public function getName(): string
    {
        return 'google';
    }

    public function getAuthorizationUrl(string $state, ?string $nonce, array $pkce): string
    {
        $this->expectedNonce = $nonce;
        $league = $this->leagueProvider();

        $options = [
            'state' => $state,
            'scope' => $this->config['scopes'] ?? ['openid', 'email', 'profile'],
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
        $email = isset($claims['email']) ? (string) $claims['email'] : null;
        $verified = (bool) ($claims['email_verified'] ?? false);
        $name = isset($claims['name']) ? (string) $claims['name'] : null;
        $picture = isset($claims['picture']) ? (string) $claims['picture'] : null;

        if ($sub === '') {
            throw new ProviderException('Google ID token missing sub');
        }

        return new NormalizedIdentity(
            provider: 'google',
            subject: $sub,
            email: $email,
            emailVerified: $verified,
            name: $name,
            avatarUrl: $picture,
            username: null,
            rawClaims: $claims,
        );
    }

    /** @internal test helper — set nonce used during callback validation when auth URL wasn't called */
    public function setExpectedNonce(?string $nonce): void
    {
        $this->expectedNonce = $nonce;
    }

    private function exchangeCode(string $code, string $verifier): AccessToken
    {
        if ($this->tokenExchanger !== null) {
            return ($this->tokenExchanger)($code, $verifier);
        }

        $league = $this->leagueProvider();
        $params = ['code' => $code];
        if ($verifier !== '') {
            $params['code_verifier'] = $verifier;
        }

        return $league->getAccessToken('authorization_code', $params);
    }

    private function leagueProvider(): Google
    {
        return new Google([
            'clientId' => $this->config['client_id'],
            'clientSecret' => $this->config['client_secret'],
            'redirectUri' => $this->config['redirect_uri'],
        ]);
    }

    private function idTokenValidator(): OidcIdTokenValidator
    {
        if ($this->validator !== null) {
            return $this->validator;
        }

        $discoveryClient = $this->discoveryClient ?? new DiscoveryClient();
        $jwksUri = (string) ($this->discovery()['jwks_uri'] ?? '');
        if ($jwksUri === '') {
            throw new ProviderException('Google discovery missing jwks_uri');
        }

        return new OidcIdTokenValidator(
            self::ISSUERS,
            $this->config['client_id'],
            function () use ($discoveryClient, $jwksUri): array {
                return $discoveryClient->fetchJson($jwksUri);
            },
        );
    }

    /** @return array<string, mixed> */
    private function discovery(): array
    {
        if ($this->discovery === null) {
            $client = $this->discoveryClient ?? new DiscoveryClient();
            $this->discovery = $client->fetchJson(self::DISCOVERY);
        }

        return $this->discovery;
    }
}
