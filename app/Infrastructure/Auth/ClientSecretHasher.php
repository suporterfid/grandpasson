<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

/**
 * Service-client secret hashing (S2). Prefers Argon2id when available.
 */
final class ClientSecretHasher
{
    public static function hash(string $plaintext): string
    {
        if ($plaintext === '') {
            throw new \InvalidArgumentException('client secret must not be empty');
        }

        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        $hash = password_hash($plaintext, $algo);
        if ($hash === false) {
            throw new \RuntimeException('password_hash failed');
        }

        return $hash;
    }

    public static function verify(string $plaintext, string $hash): bool
    {
        if ($hash === '') {
            return false;
        }

        return password_verify($plaintext, $hash);
    }

    public static function algorithmLabel(): string
    {
        return defined('PASSWORD_ARGON2ID') ? 'argon2id' : 'bcrypt';
    }
}
