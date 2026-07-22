<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Http\Controllers\HealthController;
use GrandpaSSOn\Http\Controllers\LoginController;
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
    }

    public function testUnknownRouteReturnsNull(): void
    {
        $router = $this->wiredRouter();
        $this->assertNull($router->match('GET', '/nope'));
        $this->assertNull($router->match('DELETE', '/session'));
    }

    private function wiredRouter(): Router
    {
        $router = new Router();
        $router->get('/', HealthController::class, 'index');
        $router->get('/login', LoginController::class, 'chooser');
        $router->get('/login/{provider}', LoginController::class, 'start');
        $router->get('/callback/{provider}', LoginController::class, 'start');
        $router->get('/session', \GrandpaSSOn\Http\Controllers\SessionController::class, 'show');
        $router->post('/session/exchange', SessionExchangeController::class, 'exchange');

        return $router;
    }
}
