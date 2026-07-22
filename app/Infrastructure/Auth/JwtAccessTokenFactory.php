<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use GrandpaSSOn\Domain\AccessToken;
use Firebase\JWT\JWT;

/**
 * Optional short-lived JWT companion for opaque access tokens (R15).
 * Opaque token remains authoritative for revocation; JWT is a fast-path until exp.
 */
final class JwtAccessTokenFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function enabled(array $config): bool
    {
        $jwt = $config['jwt'] ?? [];
        if (!is_array($jwt)) {
            return false;
        }
        if (!(bool) ($jwt['enabled'] ?? false)) {
            return false;
        }

        return (string) ($jwt['hmac_secret'] ?? '') !== '';
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function mint(array $config, AccessToken $record): string
    {
        if (!self::enabled($config)) {
            throw new \RuntimeException('JWT access tokens are disabled');
        }
        $secret = (string) $config['jwt']['hmac_secret'];
        $iss = (string) ($config['broker']['base_url'] ?? '');
        $exp = strtotime($record->expiresAt . ' UTC');
        $iat = strtotime($record->createdAt . ' UTC');
        if ($exp === false || $iat === false) {
            throw new \RuntimeException('Invalid token timestamps');
        }

        $payload = [
            'iss' => $iss,
            'iat' => $iat,
            'exp' => $exp,
            'jti' => $record->id,
            'token_use' => $record->isPat() ? 'pat' : 'access',
            'scope' => $record->scope,
            'client_id' => $record->clientId ?? $record->oauthClientId,
            'sub' => $record->subjectUserId,
            'aud' => $record->aud,
            'tenant' => $record->tenantId,
        ];

        return JWT::encode($payload, $secret, 'HS256');
    }
}
