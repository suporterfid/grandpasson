<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

/**
 * Opaque access-token minting and SHA-256 verification (spec §6.4, S2–S3).
 * Plaintext tokens are never persisted by this helper — callers store hash() only.
 */
final class OpaqueToken
{
    public const PREFIX = 'gpat_live_';

    /** Minimum entropy for the random segment (≥32 bytes). */
    public const RANDOM_BYTES = 32;

    /**
     * Mint a new opaque bearer token: gpat_live_ + base64url(random_bytes).
     */
    public static function mint(): string
    {
        return self::PREFIX . self::base64Url(random_bytes(self::RANDOM_BYTES));
    }

    /** SHA-256 hex digest of the full token (what gets stored / looked up). */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Constant-time compare of a presented token against a stored hash.
     * Always hashes the candidate before comparing (no early-return on format).
     */
    public static function verify(string $token, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hash($token));
    }

    /**
     * Structural check only (not a secret compare). Used for request shaping.
     */
    public static function hasExpectedShape(string $token): bool
    {
        if (!str_starts_with($token, self::PREFIX)) {
            return false;
        }
        $payload = substr($token, strlen(self::PREFIX));

        return $payload !== ''
            && strlen($payload) >= 43 // base64url of 32 bytes ≈ 43 chars
            && preg_match('/^[A-Za-z0-9_-]+$/', $payload) === 1;
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
