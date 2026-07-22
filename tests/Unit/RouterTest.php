<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Http\AppRoutes;
use GrandpaSSOn\Http\Controllers\CallbackController;
use GrandpaSSOn\Http\Controllers\HealthController;
use GrandpaSSOn\Http\Controllers\LoginController;
use GrandpaSSOn\Http\Controllers\OAuthIntrospectController;
use GrandpaSSOn\Http\Controllers\OAuthRevokeController;
use GrandpaSSOn\Http\Controllers\OAuthTokenController;
use GrandpaSSOn\Http\Controllers\SessionExchangeController;
use GrandpaSSOn\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesExactRoutes(): void
    {
        $router = $this->wiredRouter();

        $match = $router->match('GET', '/');
        $this->assertNotNull($match);
        $this->assertSame(HealthController::class, $match[0]);
        $this->assertSame('index', $match[1]);
        $this->assertSame([], $match[2]);

        $exchange = $router->match('POST', '/session/exchange');
        $this->assertNotNull($exchange);
        $this->assertSame(SessionExchangeController::class, $exchange[0]);
        $this->assertSame('exchange', $exchange[1]);

        $token = $router->match('POST', '/oauth/token');
        $this->assertNotNull($token);
        $this->assertSame(OAuthTokenController::class, $token[0]);
        $this->assertSame('token', $token[1]);

        $introspect = $router->match('POST', '/oauth/introspect');
        $this->assertNotNull($introspect);
        $this->assertSame(OAuthIntrospectController::class, $introspect[0]);
        $this->assertSame('introspect', $introspect[1]);

        $revoke = $router->match('POST', '/oauth/revoke');
        $this->assertNotNull($revoke);
        $this->assertSame(OAuthRevokeController::class, $revoke[0]);
        $this->assertSame('revoke', $revoke[1]);
    }

    public function testStripsQueryStringAndTrailingSlash(): void
    {
        $router = $this->wiredRouter();

        $match = $router->match('GET', '/session/?x=1');
        $this->assertNotNull($match);
        $this->assertSame('show', $match[1]);
    }

    public function testExtractsPathParams(): void
    {
        $router = $this->wiredRouter();

        $match = $router->match('GET', '/login/google');
        $this->assertNotNull($match);
        $this->assertSame(LoginController::class, $match[0]);
        $this->assertSame('start', $match[1]);
        $this->assertSame(['provider' => 'google'], $match[2]);

        $cb = $router->match('GET', '/callback/github');
        $this->assertNotNull($cb);
        $this->assertSame(CallbackController::class, $cb[0]);
        $this->assertSame('handle', $cb[1]);
        $this->assertSame(['provider' => 'github'], $cb[2]);
    }

    public function testUnknownRouteReturnsNull(): void
    {
        $router = $this->wiredRouter();
        $this->assertNull($router->match('GET', '/nope'));
        $this->assertNull($router->match('DELETE', '/session'));
    }

    public function testP0OauthSurfaceIsRegistered(): void
    {
        $paths = [];
        foreach (AppRoutes::definitions() as [$method, $path]) {
            $paths[] = $method . ' ' . $path;
        }
        $this->assertContains('POST /oauth/token', $paths);
        $this->assertContains('POST /oauth/introspect', $paths);
        $this->assertContains('POST /oauth/revoke', $paths);
        $this->assertContains('POST /session/exchange', $paths);
        $this->assertContains('GET /admin', $paths);
        $this->assertContains('POST /admin/api', $paths);
        $this->assertContains('GET /site/{site_id}/login/{provider}', $paths);
        $this->assertContains('GET /site/{site_id}/session', $paths);
        $this->assertContains('GET /.well-known/jwks.json', $paths);
        $this->assertContains('GET /me/pats', $paths);
        $this->assertContains('POST /me/pats', $paths);
        $this->assertContains('POST /me/pats/{id}/revoke', $paths);
    }

    public function testOauthControllersApplyRateLimitGate(): void
    {
        foreach ([
            dirname(__DIR__, 2) . '/app/Http/Controllers/OAuthTokenController.php',
            dirname(__DIR__, 2) . '/app/Http/Controllers/OAuthIntrospectController.php',
            dirname(__DIR__, 2) . '/app/Http/Controllers/OAuthRevokeController.php',
        ] as $file) {
            $src = (string) file_get_contents($file);
            $this->assertTrue(
                str_contains($src, 'RateLimitGate::allowDb') || str_contains($src, 'RateLimitGate::allow'),
                basename($file),
            );
        }
        $tokenSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/OAuthTokenController.php');
        $introSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/OAuthIntrospectController.php');
        $this->assertStringContainsString('RateLimitGate::allowDb', $tokenSrc);
        $this->assertStringContainsString('RateLimitGate::allowDb', $introSrc);

        $loginSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/LoginController.php');
        $readerSrc = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Http/Controllers/SiteReaderController.php');
        $this->assertStringContainsString('RateLimitGate::allowLogin', $loginSrc);
        $this->assertStringContainsString('RateLimitGate::allowLogin', $readerSrc);
    }

    private function wiredRouter(): Router
    {
        $router = new Router();
        AppRoutes::register($router);

        return $router;
    }
}
