<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Infrastructure\Auth\ClientSecretHasher;
use GrandpaSSOn\Infrastructure\Auth\OpaqueToken;
use GrandpaSSOn\Support\AccessTokenTtl;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;

final class OpaqueTokenCryptoTest extends TestCase
{
    public function testMintHasPrefixAndEnoughEntropy(): void
    {
        $token = OpaqueToken::mint();
        $this->assertStringStartsWith(OpaqueToken::PREFIX, $token);
        $this->assertTrue(OpaqueToken::hasExpectedShape($token));

        $payload = substr($token, strlen(OpaqueToken::PREFIX));
        // base64url of 32 bytes is 43 chars without padding.
        $this->assertGreaterThanOrEqual(43, strlen($payload));
    }

    public function testHashIsSha256AndVerifyUsesHashEquals(): void
    {
        $token = OpaqueToken::mint();
        $hash = OpaqueToken::hash($token);

        $this->assertSame(64, strlen($hash));
        $this->assertSame(hash('sha256', $token), $hash);
        $this->assertTrue(OpaqueToken::verify($token, $hash));
        $this->assertFalse(OpaqueToken::verify($token . 'x', $hash));
        $this->assertFalse(OpaqueToken::verify(OpaqueToken::mint(), $hash));

        $ref = new ReflectionFunction('hash_equals');
        $this->assertTrue($ref->isInternal());
        // Ensure verify path is implemented via hash_equals (source scan).
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Infrastructure/Auth/OpaqueToken.php');
        $this->assertStringContainsString('hash_equals', $src);
    }

    public function testPlaintextTokenIsNotTheStoredForm(): void
    {
        $token = OpaqueToken::mint();
        $hash = OpaqueToken::hash($token);
        $this->assertNotSame($token, $hash);
        $this->assertStringNotContainsString(OpaqueToken::PREFIX, $hash);
    }

    public function testClientSecretHasherRoundTrip(): void
    {
        $secret = 'svc-secret-' . bin2hex(random_bytes(8));
        $hash = ClientSecretHasher::hash($secret);

        $this->assertNotSame($secret, $hash);
        $this->assertTrue(ClientSecretHasher::verify($secret, $hash));
        $this->assertFalse(ClientSecretHasher::verify($secret . 'nope', $hash));
        $this->assertContains(ClientSecretHasher::algorithmLabel(), ['argon2id', 'bcrypt']);
    }

    public function testAccessTokenTtlDefaultsAndClamp(): void
    {
        $config = [
            'access_ttl_seconds' => 900,
            'access_ttl_max_seconds' => 3600,
        ];

        $this->assertSame(900, AccessTokenTtl::resolve($config));
        $this->assertSame(600, AccessTokenTtl::resolve($config, 600));
        $this->assertSame(3600, AccessTokenTtl::resolve($config, 99999));
        $this->assertSame(900, AccessTokenTtl::resolve($config, 0));
    }
}
