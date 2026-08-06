<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class Locale
{
    public const DEFAULT = 'pt-BR';

    /** @var list<string> */
    public const SUPPORTED = ['pt-BR', 'en'];

    public static function isSupported(string $value): bool
    {
        return in_array($value, self::SUPPORTED, true);
    }
}
