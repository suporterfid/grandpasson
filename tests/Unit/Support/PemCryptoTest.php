<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Support;

use GrandpaSSOn\Support\PemCrypto;
use PHPUnit\Framework\TestCase;

final class PemCryptoTest extends TestCase
{
    public function testEncryptDecryptRoundTrip(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\nMIIE\n-----END PRIVATE KEY-----\n";
        $secret = 'test-encryption-secret-32b-min';
        $stored = PemCrypto::encrypt($pem, $secret);
        $this->assertTrue(PemCrypto::isEncrypted($stored));
        $this->assertStringStartsWith('enc:v1:', $stored);
        $this->assertSame($pem, PemCrypto::decrypt($stored, $secret));
    }

    public function testLegacyPlaintextPassthrough(): void
    {
        $pem = "-----BEGIN PRIVATE KEY-----\nplain\n-----END PRIVATE KEY-----\n";
        $this->assertSame($pem, PemCrypto::decrypt($pem, 'any-secret'));
        $this->assertFalse(PemCrypto::isEncrypted($pem));
    }

    public function testWrongSecretFails(): void
    {
        $stored = PemCrypto::encrypt('secret-pem', 'correct-secret');
        $this->expectException(\RuntimeException::class);
        PemCrypto::decrypt($stored, 'wrong-secret');
    }
}
