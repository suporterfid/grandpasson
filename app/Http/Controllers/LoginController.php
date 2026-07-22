<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

/**
 * Placeholder until M3 (T6). Exists so the router can wire public endpoints now.
 */
final class LoginController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function chooser(array $config, array $params = []): void
    {
        $this->notImplemented('GET /login');
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function start(array $config, array $params = []): void
    {
        $this->notImplemented('GET /login/{provider}');
    }

    private function notImplemented(string $route): void
    {
        http_response_code(501);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'not_implemented', 'route' => $route], JSON_THROW_ON_ERROR);
    }
}
