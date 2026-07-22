<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Providers;

use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use PHPUnit\Framework\TestCase;

final class NormalizedIdentityAndPkceTest extends TestCase
{
    public function testNormalizedIdentityRequiresProviderAndSubject(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new NormalizedIdentity('', 'sub', null, false, null);
    }

    public function testPkceGeneratesS256Pair(): void
    {
        $pkce = Pkce::generate();
        $this->assertSame('S256', $pkce['code_challenge_method']);
        $this->assertNotSame('', $pkce['code_verifier']);
        $this->assertNotSame('', $pkce['code_challenge']);

        $expected = rtrim(strtr(base64_encode(hash('sha256', $pkce['code_verifier'], true)), '+/', '-_'), '=');
        $this->assertSame($expected, $pkce['code_challenge']);
    }
}
