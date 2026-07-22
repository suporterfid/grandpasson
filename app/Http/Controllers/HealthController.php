<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

final class HealthController
{
    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $params
     */
    public function index(array $config, array $params = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => true,
            'service' => $config['broker']['name'] ?? 'GrandpaSSOn',
            'tagline' => "SSO that runs where your grandpa's cPanel still lives.",
        ], JSON_THROW_ON_ERROR);
    }
}
