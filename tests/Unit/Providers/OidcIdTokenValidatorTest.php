<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Providers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GrandpaSSOn\Infrastructure\Providers\OidcIdTokenValidator;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use PHPUnit\Framework\TestCase;

final class OidcIdTokenValidatorTest extends TestCase
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

    public function testAcceptsValidToken(): void
    {
        $jwt = $this->mintWithKid([
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-1',
            'sub' => 'user-1',
            'email' => 'a@example.com',
            'email_verified' => true,
            'nonce' => 'n-1',
            'exp' => time() + 300,
            'iat' => time(),
        ], 'test');

        $validator = new OidcIdTokenValidator(
            ['https://accounts.google.com', 'accounts.google.com'],
            'client-1',
            null,
            ['test' => new Key($this->publicPem, 'RS256')],
        );

        $claims = $validator->validate($jwt, 'n-1');
        $this->assertSame('user-1', $claims['sub']);
    }

    public function testRejectsBadNonce(): void
    {
        $jwt = $this->mintWithKid([
            'iss' => 'https://accounts.google.com',
            'aud' => 'client-1',
            'sub' => 'user-1',
            'nonce' => 'other',
            'exp' => time() + 300,
            'iat' => time(),
        ], 'test');

        $validator = new OidcIdTokenValidator(
            ['https://accounts.google.com'],
            'client-1',
            null,
            ['test' => new Key($this->publicPem, 'RS256')],
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('nonce');
        $validator->validate($jwt, 'n-1');
    }

    public function testRejectsWrongAudience(): void
    {
        $jwt = $this->mintWithKid([
            'iss' => 'https://accounts.google.com',
            'aud' => 'other-client',
            'sub' => 'user-1',
            'exp' => time() + 300,
            'iat' => time(),
        ], 'test');

        $validator = new OidcIdTokenValidator(
            ['https://accounts.google.com'],
            'client-1',
            null,
            ['test' => new Key($this->publicPem, 'RS256')],
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('audience');
        $validator->validate($jwt, null);
    }

    /** @param array<string, mixed> $claims */
    private function mint(array $claims): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256');
    }

    /** @param array<string, mixed> $claims */
    private function mintWithKid(array $claims, string $kid): string
    {
        return JWT::encode($claims, $this->privateKey, 'RS256', $kid);
    }
}
