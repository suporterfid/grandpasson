<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Providers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GrandpaSSOn\Infrastructure\Providers\MicrosoftProvider;
use GrandpaSSOn\Infrastructure\Providers\OidcIdTokenValidator;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

final class MicrosoftProviderTest extends TestCase
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

    public function testRequiresTenantId(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('MS_TENANT_ID');
        new MicrosoftProvider([
            'client_id' => 'ms',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost/callback/microsoft',
            'tenant_id' => '',
        ]);
    }

    public function testDiscoveryUrlUsesTenantNotCommonByDefault(): void
    {
        $provider = new MicrosoftProvider([
            'client_id' => 'ms',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost/callback/microsoft',
            'tenant_id' => 'tenant-abc',
        ]);

        $this->assertStringContainsString('/tenant-abc/', $provider->discoveryUrl());
        $this->assertStringNotContainsString('/common/', $provider->discoveryUrl());
    }

    public function testUpnAloneIsNeverVerifiedEmail(): void
    {
        [$email, $verified] = MicrosoftProvider::resolveEmail([
            'preferred_username' => 'user@contoso.com',
            'upn' => 'user@contoso.com',
        ]);

        $this->assertSame('user@contoso.com', $email);
        $this->assertFalse($verified);
    }

    public function testEmailClaimCanBeVerified(): void
    {
        [$email, $verified] = MicrosoftProvider::resolveEmail([
            'email' => 'user@contoso.com',
            'email_verified' => true,
            'preferred_username' => 'user@contoso.com',
        ]);

        $this->assertSame('user@contoso.com', $email);
        $this->assertTrue($verified);
    }

    public function testHandleCallbackMapsClaims(): void
    {
        $issuer = 'https://login.microsoftonline.com/tenant-abc/v2.0';
        $claims = [
            'iss' => $issuer,
            'aud' => 'ms-client',
            'sub' => 'ms-sub-1',
            'email' => 'user@contoso.com',
            'email_verified' => true,
            'name' => 'Contoso User',
            'preferred_username' => 'user@contoso.com',
            'nonce' => 'n-ms',
            'exp' => time() + 300,
            'iat' => time(),
        ];
        $idToken = JWT::encode($claims, $this->privateKey, 'RS256', 'test');

        $validator = new OidcIdTokenValidator(
            [$issuer],
            'ms-client',
            null,
            ['test' => new Key($this->publicPem, 'RS256')],
        );

        $provider = new MicrosoftProvider(
            [
                'client_id' => 'ms-client',
                'client_secret' => 'secret',
                'redirect_uri' => 'http://localhost/callback/microsoft',
                'tenant_id' => 'tenant-abc',
            ],
            null,
            static fn (): AccessToken => new AccessToken([
                'access_token' => 'atok',
                'id_token' => $idToken,
            ]),
            $validator,
        );
        $provider->setExpectedNonce('n-ms');

        // Avoid live discovery for auth URL in this test — only callback.
        $identity = $provider->handleCallback(['code' => 'c']);
        $this->assertSame('microsoft', $identity->provider);
        $this->assertSame('ms-sub-1', $identity->subject);
        $this->assertTrue($identity->emailVerified);
    }

    public function testAuthorizationUrlUsesTenantEndpointsWhenDiscoveryInjected(): void
    {
        $discovery = new class extends \GrandpaSSOn\Infrastructure\Providers\DiscoveryClient {
            public function fetchJson(string $url): array
            {
                return [
                    'authorization_endpoint' => 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/authorize',
                    'token_endpoint' => 'https://login.microsoftonline.com/tenant-abc/oauth2/v2.0/token',
                    'jwks_uri' => 'https://example.test/jwks',
                    'issuer' => 'https://login.microsoftonline.com/tenant-abc/v2.0',
                ];
            }
        };

        $provider = new MicrosoftProvider(
            [
                'client_id' => 'ms-client',
                'client_secret' => 'secret',
                'redirect_uri' => 'http://localhost/callback/microsoft',
                'tenant_id' => 'tenant-abc',
            ],
            $discovery,
        );

        $pkce = Pkce::generate();
        $url = $provider->getAuthorizationUrl('st', 'nn', $pkce);
        $this->assertStringContainsString('login.microsoftonline.com/tenant-abc/', $url);
        $this->assertStringContainsString('state=st', $url);
        $this->assertStringContainsString('nonce=nn', $url);
    }
}
