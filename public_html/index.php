<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'service' => 'GrandpaSSOn',
    'tagline' => "SSO that runs where your grandpa's cPanel still lives.",
], JSON_THROW_ON_ERROR);
