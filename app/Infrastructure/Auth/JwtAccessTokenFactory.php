<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use GrandpaSSOn\Domain\AccessToken;
use GrandpaSSOn\Infrastructure\Db\JwtSigningKeyRepository;
use Firebase\JWT\JWT;
use PDO;

/**
 * Optional short-lived JWT companion for opaque access tokens (R15/R16).
 * Prefers active RS256 signing keys (rotatable); falls back to HS256 env secret.
 * Opaque token remains authoritative for revocation; JWT is a fast-path until exp.
 */
final class JwtAccessTokenFactory
{
    /**
     * @param array<string, mixed> $config
     */
    public static function enabled(array $config, ?PDO $pdo = null): bool
    {
        $jwt = $config['jwt'] ?? [];
        if (!is_array($jwt) || !(bool) ($jwt['enabled'] ?? false)) {
            return false;
        }
        if ($pdo !== null) {
            try {
                if (self::keys($config, $pdo)->findActive() !== null) {
                    return true;
                }
            } catch (\Throwable) {
                // Table may not exist yet during partial upgrades.
            }
        }

        return (string) ($jwt['hmac_secret'] ?? '') !== '';
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function mint(array $config, AccessToken $record, ?PDO $pdo = null): string
    {
        if (!self::enabled($config, $pdo)) {
            throw new \RuntimeException('JWT access tokens are disabled');
        }

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

        if ($pdo !== null) {
            $active = self::keys($config, $pdo)->findActive();
            if ($active !== null) {
                return JWT::encode($payload, $active->privatePem, 'RS256', $active->kid);
            }
        }

        $secret = (string) ($config['jwt']['hmac_secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('No active JWT signing key and JWT_HMAC_SECRET is empty');
        }

        return JWT::encode($payload, $secret, 'HS256');
    }

    /** @param array<string, mixed> $config */
    private static function keys(array $config, PDO $pdo): JwtSigningKeyRepository
    {
        $jwt = is_array($config['jwt'] ?? null) ? $config['jwt'] : [];

        return new JwtSigningKeyRepository(
            $pdo,
            (string) ($jwt['key_encryption_secret'] ?? ''),
            (string) ($config['app_env'] ?? 'dev'),
        );
    }
}
