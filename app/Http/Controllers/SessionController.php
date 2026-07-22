<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

final class SessionController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function show(array $config, array $params = []): void
    {
        if (empty($_SESSION['user_id'])) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => 'unauthenticated'], JSON_THROW_ON_ERROR);

            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'id' => $_SESSION['user_id'],
            'email' => $_SESSION['email'] ?? null,
            'display_name' => $_SESSION['display_name'] ?? null,
            'status' => $_SESSION['status'] ?? 'active',
        ], JSON_THROW_ON_ERROR);
    }
}
