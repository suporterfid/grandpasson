<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Domain\OAuthClient;
use PHPUnit\Framework\TestCase;

final class OAuthClientTest extends TestCase
{
    public function testExactRedirectMatchAndConfidential(): void
    {
        $client = new OAuthClient(
            'cid',
            password_hash('x', PASSWORD_DEFAULT),
            'App',
            ['https://app.example/cb'],
            'confidential',
            true,
        );

        $this->assertTrue($client->isConfidential());
        $this->assertTrue($client->allowsRedirectUri('https://app.example/cb'));
        $this->assertFalse($client->allowsRedirectUri('https://app.example/cb/'));
        $this->assertFalse($client->allowsRedirectUri('https://evil.example/cb'));
    }
}
