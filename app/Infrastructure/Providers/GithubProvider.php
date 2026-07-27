<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

use League\OAuth2\Client\Provider\Github;
use League\OAuth2\Client\Token\AccessToken;

final class GithubProvider implements ProviderInterface
{
    /**
     * @param array{client_id: string, client_secret: string, redirect_uri: string, scopes?: list<string>} $config
     * @param null|callable(string, string): AccessToken $tokenExchanger
     * @param null|callable(AccessToken): array{profile: array<string, mixed>, emails: list<array<string, mixed>>} $profileFetcher
     */
    public function __construct(
        private readonly array $config,
        private mixed $tokenExchanger = null,
        private mixed $profileFetcher = null,
    ) {
        if ($this->tokenExchanger !== null && !is_callable($this->tokenExchanger)) {
            throw new \InvalidArgumentException('tokenExchanger must be callable');
        }
        if ($this->profileFetcher !== null && !is_callable($this->profileFetcher)) {
            throw new \InvalidArgumentException('profileFetcher must be callable');
        }
    }

    public function getName(): string
    {
        return 'github';
    }

    public function getAuthorizationUrl(string $state, ?string $nonce, array $pkce): string
    {
        // GitHub is OAuth2 (no OIDC nonce). PKCE still applied as defense in depth.
        unset($nonce);

        return $this->leagueProvider()->getAuthorizationUrl([
            'state' => $state,
            'scope' => $this->config['scopes'] ?? ['read:user', 'user:email'],
            'code_challenge' => $pkce['code_challenge'],
            'code_challenge_method' => $pkce['code_challenge_method'],
        ]);
    }

    public function handleCallback(array $request): NormalizedIdentity
    {
        $code = (string) ($request['code'] ?? '');
        if ($code === '') {
            throw new ProviderException('Missing authorization code');
        }

        $verifier = (string) ($request['code_verifier'] ?? '');
        $token = $this->exchangeCode($code, $verifier);
        $bundle = $this->fetchProfile($token);

        $profile = $bundle['profile'];
        $emails = $bundle['emails'];

        $id = $profile['id'] ?? null;
        if ($id === null || $id === '') {
            throw new ProviderException('GitHub profile missing id');
        }

        $login = isset($profile['login']) ? (string) $profile['login'] : null;
        $name = isset($profile['name']) && is_string($profile['name']) ? $profile['name'] : $login;
        $avatar = isset($profile['avatar_url']) ? (string) $profile['avatar_url'] : null;

        [$email, $verified] = self::pickVerifiedEmail($profile, $emails);

        return new NormalizedIdentity(
            provider: 'github',
            subject: (string) $id,
            email: $email,
            emailVerified: $verified,
            name: $name,
            avatarUrl: $avatar,
            username: $login,
            rawClaims: [
                'profile' => $profile,
                'emails' => $emails,
            ],
        );
    }

    /**
     * Prefer primary verified email from /user/emails; fall back to profile email only if marked verified.
     *
     * @param array<string, mixed> $profile
     * @param list<array<string, mixed>> $emails
     * @return array{0: ?string, 1: bool}
     */
    public static function pickVerifiedEmail(array $profile, array $emails): array
    {
        $primaryVerified = null;
        $anyVerified = null;

        foreach ($emails as $row) {
            if (!is_array($row)) {
                continue;
            }
            $addr = isset($row['email']) ? (string) $row['email'] : '';
            if ($addr === '') {
                continue;
            }
            $isVerified = !empty($row['verified']);
            if (!$isVerified) {
                continue;
            }
            $anyVerified ??= $addr;
            if (!empty($row['primary'])) {
                $primaryVerified = $addr;
                break;
            }
        }

        if ($primaryVerified !== null) {
            return [$primaryVerified, true];
        }
        if ($anyVerified !== null) {
            return [$anyVerified, true];
        }

        // Do not trust profile.email without the emails API verification flag.
        return [null, false];
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

    /**
     * @return array{profile: array<string, mixed>, emails: list<array<string, mixed>>}
     */
    private function fetchProfile(AccessToken $token): array
    {
        if ($this->profileFetcher !== null) {
            return ($this->profileFetcher)($token);
        }

        $league = $this->leagueProvider();
        $owner = $league->getResourceOwner($token);
        $profile = $owner->toArray();

        $request = $league->getAuthenticatedRequest('GET', 'https://api.github.com/user/emails', $token);
        $response = $league->getParsedResponse($request);
        $emails = is_array($response) ? $response : [];

        /** @var list<array<string, mixed>> $emails */
        return ['profile' => $profile, 'emails' => $emails];
    }

    private function leagueProvider(): Github
    {
        return new Github([
            'clientId' => $this->config['client_id'],
            'clientSecret' => $this->config['client_secret'],
            'redirectUri' => $this->config['redirect_uri'],
        ]);
    }
}
