<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http;

use GrandpaSSOn\Http\Controllers\ActiveTenantController;
use GrandpaSSOn\Http\Controllers\AdminUiController;
use GrandpaSSOn\Http\Controllers\CallbackController;
use GrandpaSSOn\Http\Controllers\HealthController;
use GrandpaSSOn\Http\Controllers\JwksController;
use GrandpaSSOn\Http\Controllers\LoginController;
use GrandpaSSOn\Http\Controllers\LogoutController;
use GrandpaSSOn\Http\Controllers\OAuthIntrospectController;
use GrandpaSSOn\Http\Controllers\OAuthRevokeController;
use GrandpaSSOn\Http\Controllers\OAuthTokenController;
use GrandpaSSOn\Http\Controllers\SessionController;
use GrandpaSSOn\Http\Controllers\SessionExchangeController;
use GrandpaSSOn\Http\Controllers\SiteReaderController;
use GrandpaSSOn\Http\Controllers\UserPatController;

/**
 * Single source of truth for front-controller route wiring (v0 + v1 P0).
 */
final class AppRoutes
{
    /**
     * @return list<array{0: string, 1: string, 2: class-string, 3: string}>
     *   [HTTP method, path, controller, action]
     */
    public static function definitions(): array
    {
        return [
            ['GET', '/', HealthController::class, 'index'],
            ['GET', '/.well-known/jwks.json', JwksController::class, 'show'],
            ['GET', '/login', LoginController::class, 'chooser'],
            ['GET', '/login/{provider}', LoginController::class, 'start'],
            ['GET', '/callback/{provider}', CallbackController::class, 'handle'],
            ['POST', '/logout', LogoutController::class, 'handle'],
            ['GET', '/session', SessionController::class, 'show'],
            ['POST', '/session/exchange', SessionExchangeController::class, 'exchange'],
            ['POST', '/oauth/token', OAuthTokenController::class, 'token'],
            ['POST', '/oauth/introspect', OAuthIntrospectController::class, 'introspect'],
            ['POST', '/oauth/revoke', OAuthRevokeController::class, 'revoke'],
            ['GET', '/admin', AdminUiController::class, 'index'],
            ['POST', '/admin/api', AdminUiController::class, 'api'],
            ['GET', '/me/pats', UserPatController::class, 'list'],
            ['POST', '/me/pats', UserPatController::class, 'create'],
            ['POST', '/me/pats/{id}/revoke', UserPatController::class, 'revoke'],
            ['GET', '/me/active-tenant', ActiveTenantController::class, 'show'],
            ['POST', '/me/active-tenant', ActiveTenantController::class, 'set'],
            ['GET', '/site/{site_id}/login', SiteReaderController::class, 'chooser'],
            ['GET', '/site/{site_id}/login/{provider}', SiteReaderController::class, 'login'],
            ['GET', '/site/{site_id}/session', SiteReaderController::class, 'session'],
            ['POST', '/site/{site_id}/logout', SiteReaderController::class, 'logout'],
        ];
    }

    public static function register(Router $router): void
    {
        foreach (self::definitions() as [$method, $path, $controller, $action]) {
            if ($method === 'GET') {
                $router->get($path, $controller, $action);
            } else {
                $router->post($path, $controller, $action);
            }
        }
    }
}
