<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(16));
        }

        return $_SESSION['csrf'];
    }

    public static function validate(?string $provided): bool
    {
        $expected = $_SESSION['csrf'] ?? '';
        if (!is_string($expected) || $expected === '' || $provided === null || $provided === '') {
            return false;
        }

        return hash_equals($expected, $provided);
    }
}
