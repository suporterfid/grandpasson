<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

/**
 * AES-256-GCM helper for JWT private PEMs at rest (S2 / R16).
 * Stored form: enc:v1:<base64(iv || tag || ciphertext)>
 */
final class PemCrypto
{
    private const PREFIX = 'enc:v1:';
    private const IV_LEN = 12;
    private const TAG_LEN = 16;

    public static function isEncrypted(string $stored): bool
    {
        return str_starts_with($stored, self::PREFIX);
    }

    public static function encrypt(string $plaintext, string $secret): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('Encryption secret is required');
        }
        $key = hash('sha256', $secret, true);
        $iv = random_bytes(self::IV_LEN);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN);
        if ($cipher === false || strlen($tag) !== self::TAG_LEN) {
            throw new \RuntimeException('openssl_encrypt failed for PEM');
        }

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored, string $secret): string
    {
        if (!self::isEncrypted($stored)) {
            return $stored;
        }
        if ($secret === '') {
            throw new \RuntimeException('Encrypted PEM requires JWT_KEY_ENCRYPTION_SECRET');
        }
        $raw = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < self::IV_LEN + self::TAG_LEN + 1) {
            throw new \RuntimeException('Invalid encrypted PEM payload');
        }
        $iv = substr($raw, 0, self::IV_LEN);
        $tag = substr($raw, self::IV_LEN, self::TAG_LEN);
        $cipher = substr($raw, self::IV_LEN + self::TAG_LEN);
        $key = hash('sha256', $secret, true);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new \RuntimeException('openssl_decrypt failed for PEM (wrong secret?)');
        }

        return $plain;
    }
}
