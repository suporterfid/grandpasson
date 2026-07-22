<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

final class Pkce
{
    /**
     * @return array{code_verifier: string, code_challenge: string, code_challenge_method: 'S256'}
     */
    public static function generate(): array
    {
        $verifier = self::base64Url(random_bytes(32));
        $challenge = self::base64Url(hash('sha256', $verifier, true));

        return [
            'code_verifier' => $verifier,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ];
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
