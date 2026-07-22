<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Support;

use GrandpaSSOn\Support\HttpsEnforcer;
use PHPUnit\Framework\TestCase;

final class HttpsEnforcerTest extends TestCase
{
    public function testDevDoesNotRedirectOverHttp(): void
    {
        $result = HttpsEnforcer::evaluate(
            ['force_https' => false, 'broker' => ['base_url' => 'http://localhost:8080']],
            ['HTTPS' => 'off', 'HTTP_HOST' => 'localhost:8080', 'REQUEST_URI' => '/login'],
        );
        $this->assertFalse($result['enforced']);
        $this->assertNull($result['redirect_url']);
    }

    public function testProdRedirectsCleartextUsingBrokerBaseUrl(): void
    {
        $result = HttpsEnforcer::evaluate(
            ['force_https' => true, 'broker' => ['base_url' => 'https://auth.example.com']],
            ['HTTPS' => 'off', 'HTTP_HOST' => 'auth.example.com', 'REQUEST_URI' => '/oauth/token'],
        );
        $this->assertTrue($result['enforced']);
        $this->assertFalse($result['is_https']);
        $this->assertSame('https://auth.example.com/oauth/token', $result['redirect_url']);
    }

    public function testForwardedProtoCountsAsHttps(): void
    {
        $result = HttpsEnforcer::evaluate(
            ['force_https' => true, 'broker' => ['base_url' => 'https://auth.example.com']],
            [
                'HTTPS' => 'off',
                'HTTP_X_FORWARDED_PROTO' => 'https, http',
                'HTTP_HOST' => 'auth.example.com',
                'REQUEST_URI' => '/',
            ],
        );
        $this->assertTrue($result['is_https']);
        $this->assertNull($result['redirect_url']);
    }

    public function testIsHttpsRequestHelpers(): void
    {
        $this->assertTrue(HttpsEnforcer::isHttpsRequest(['HTTPS' => 'on']));
        $this->assertTrue(HttpsEnforcer::isHttpsRequest(['SERVER_PORT' => '443']));
        $this->assertTrue(HttpsEnforcer::isHttpsRequest(['HTTP_X_FORWARDED_SSL' => 'on']));
        $this->assertFalse(HttpsEnforcer::isHttpsRequest(['HTTPS' => 'off', 'SERVER_PORT' => '80']));
    }
}
