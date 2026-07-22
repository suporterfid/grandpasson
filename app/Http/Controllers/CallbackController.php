<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

final class CallbackController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function handle(array $config, array $params = []): void
    {
        http_response_code(501);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'not_implemented', 'route' => 'GET /callback/{provider}'], JSON_THROW_ON_ERROR);
    }
}
