<?php

declare(strict_types=1);

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Http\AppRoutes;
use GrandpaSSOn\Http\Router;
use GrandpaSSOn\Infrastructure\Session\SessionBootstrap;
use GrandpaSSOn\Support\HttpsEnforcer;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $config = ConfigLoader::load();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'config', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
    exit(1);
}

// S7: redirect cleartext when APP_ENV=prod or FORCE_HTTPS=true (dev/local stays HTTP-friendly).
if (HttpsEnforcer::enforceOrContinue($config)) {
    exit(0);
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
AppRoutes::register($router);

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
