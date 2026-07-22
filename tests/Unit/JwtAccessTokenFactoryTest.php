<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Domain\AccessToken;
use GrandpaSSOn\Infrastructure\Auth\JwtAccessTokenFactory;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

final class JwtAccessTokenFactoryTest extends TestCase
{
    public function testMintsHs256JwtWithJti(): void
    {
        $config = [
            'broker' => ['base_url' => 'https://auth.example.com'],
            'jwt' => ['enabled' => true, 'hmac_secret' => 'test-secret-at-least-32-chars-long'],
        ];
        $record = new AccessToken(
            id: 'tok-1',
            tokenHash: str_repeat('a', 64),
            clientId: 'svc-1',
            subjectUserId: null,
            scope: 'kb:read',
            aud: 'workspace/x',
            tenantId: null,
            expiresAt: gmdate('Y-m-d H:i:s', time() + 900),
            revokedAt: null,
            createdAt: gmdate('Y-m-d H:i:s'),
            lastUsedAt: null,
        );

        $jwt = JwtAccessTokenFactory::mint($config, $record);
        $decoded = (array) JWT::decode($jwt, new Key('test-secret-at-least-32-chars-long', 'HS256'));
        $this->assertSame('tok-1', $decoded['jti']);
        $this->assertSame('svc-1', $decoded['client_id']);
        $this->assertSame('kb:read', $decoded['scope']);
        $this->assertSame('https://auth.example.com', $decoded['iss']);
    }

    public function testDisabledWithoutSecret(): void
    {
        $this->assertFalse(JwtAccessTokenFactory::enabled([
            'jwt' => ['enabled' => true, 'hmac_secret' => ''],
        ]));
    }
}
