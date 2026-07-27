<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Providers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GrandpaSSOn\Infrastructure\Providers\GoogleProvider;
use GrandpaSSOn\Infrastructure\Providers\OidcIdTokenValidator;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

final class GoogleProviderTest extends TestCase
{
    /** @var resource */
    private $privateKey;

    private string $publicPem;

    protected function setUp(): void
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($key);
        openssl_pkey_export($key, $privatePem);
        $details = openssl_pkey_get_details($key);
        $this->assertIsArray($details);
        $this->publicPem = $details['key'];
        $this->privateKey = openssl_pkey_get_private($privatePem);
    }

    public function testAuthorizationUrlIncludesStateNonceAndPkce(): void
    {
        $provider = new GoogleProvider([
            'client_id' => 'g-client',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8080/callback/google',
        ]);

        $pkce = Pkce::generate();
        $url = $provider->getAuthorizationUrl('state-1', 'nonce-1', $pkce);

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('state=state-1', $url);
        $this->assertStringContainsString('nonce=nonce-1', $url);
        $this->assertStringContainsString('code_challenge=' . $pkce['code_challenge'], $url);
        $this->assertStringContainsString('code_challenge_method=S256', $url);
    }

    public function testHandleCallbackReturnsNormalizedIdentity(): void
    {
        $claims = [
            'iss' => 'https://accounts.google.com',
            'aud' => 'g-client',
            'sub' => 'google-sub-1',
            'email' => 'user@example.com',
            'email_verified' => true,
            'name' => 'User Example',
            'picture' => 'https://example.com/a.png',
            'nonce' => 'nonce-1',
            'exp' => time() + 300,
            'iat' => time(),
        ];
        $idToken = JWT::encode($claims, $this->privateKey, 'RS256', 'test');

        $validator = new OidcIdTokenValidator(
            ['https://accounts.google.com'],
            'g-client',
            null,
            ['test' => new Key($this->publicPem, 'RS256')],
        );

        $provider = new GoogleProvider(
            [
                'client_id' => 'g-client',
                'client_secret' => 'secret',
                'redirect_uri' => 'http://localhost:8080/callback/google',
            ],
            null,
            static fn (): AccessToken => new AccessToken([
                'access_token' => 'atok',
                'id_token' => $idToken,
            ]),
            $validator,
        );
        $provider->setExpectedNonce('nonce-1');

        $identity = $provider->handleCallback(['code' => 'auth-code']);

        $this->assertSame('google', $identity->provider);
        $this->assertSame('google-sub-1', $identity->subject);
        $this->assertSame('user@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
        $this->assertSame('User Example', $identity->name);
        $this->assertSame('https://example.com/a.png', $identity->avatarUrl);
    }
}
