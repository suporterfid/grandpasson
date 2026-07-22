<?php

declare(strict_types=1);

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Http\Controllers\CallbackController;
use GrandpaSSOn\Http\Controllers\HealthController;
use GrandpaSSOn\Http\Controllers\LoginController;
use GrandpaSSOn\Http\Controllers\LogoutController;
use GrandpaSSOn\Http\Controllers\SessionController;
use GrandpaSSOn\Http\Controllers\SessionExchangeController;
use GrandpaSSOn\Http\Router;
use GrandpaSSOn\Infrastructure\Session\SessionBootstrap;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = ConfigLoader::load();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'config', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
}

try {
    SessionBootstrap::start($config);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'session', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
}

$router = new Router();
$router->get('/', HealthController::class, 'index');
$router->get('/login', LoginController::class, 'chooser');
$router->get('/login/{provider}', LoginController::class, 'start');
$router->get('/callback/{provider}', CallbackController::class, 'handle');
$router->post('/logout', LogoutController::class, 'handle');
$router->get('/session', SessionController::class, 'show');
$router->post('/session/exchange', SessionExchangeController::class, 'exchange');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$match = $router->match($method, $uri);

if ($match === null) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
    exit(0);
}

[$controllerClass, $action, $params] = $match;
$controller = new $controllerClass();
$controller->$action($config, $params);
