<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\AccessToken;
use GrandpaSSOn\Http\Controllers\JwksController;
use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Auth\JwtAccessTokenFactory;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\JwtSigningKeyRepository;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use PDO;
use PHPUnit\Framework\TestCase;

final class JwtSigningKeyRotationTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        Connection::reset();
        $this->dbName = 'gp_jwt_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Connection::reset();
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testRotatePublishesJwksAndSignsWithKid(): void
    {
        $admin = AdminCommandRunner::fromPdo($this->pdo, [
            'app_env' => 'dev',
            'jwt' => ['key_encryption_secret' => ''],
        ]);
        $first = $admin->run('jwt:key-rotate', []);
        $this->assertTrue($first['ok']);
        $kid1 = (string) $first['kid'];

        $second = $admin->run('jwt:key-rotate', []);
        $kid2 = (string) $second['kid'];
        $this->assertNotSame($kid1, $kid2);

        $listed = $admin->run('jwt:key-list', []);
        $this->assertSame(2, $listed['count']);
        $byKid = [];
        foreach ($listed['keys'] as $row) {
            $byKid[$row['kid']] = $row['status'];
        }
        $this->assertSame('retiring', $byKid[$kid1]);
        $this->assertSame('active', $byKid[$kid2]);

        $config = [
            'app_env' => 'dev',
            'broker' => ['base_url' => 'https://auth.example.com'],
            'jwt' => ['enabled' => true, 'hmac_secret' => '', 'key_encryption_secret' => ''],
            'db' => [
                'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('TEST_DB_PORT') ?: '3306'),
                'name' => $this->dbName,
                'user' => getenv('TEST_DB_USER') ?: 'root',
                'password' => getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
                    ? (string) getenv('TEST_DB_PASS')
                    : 'devrootpass',
            ],
        ];

        $record = new AccessToken(
            id: 'tok-rs',
            tokenHash: str_repeat('b', 64),
            clientId: 'svc',
            subjectUserId: null,
            scope: 'kb:read',
            aud: null,
            tenantId: null,
            expiresAt: gmdate('Y-m-d H:i:s', time() + 900),
            revokedAt: null,
            createdAt: gmdate('Y-m-d H:i:s'),
            lastUsedAt: null,
        );
        $this->assertTrue(JwtAccessTokenFactory::enabled($config, $this->pdo));
        $jwt = JwtAccessTokenFactory::mint($config, $record, $this->pdo);

        Connection::reset();
        http_response_code(200);
        ob_start();
        (new JwksController())->show($config);
        $jwks = json_decode((string) ob_get_clean(), true);
        $this->assertSame(200, http_response_code());
        $this->assertCount(2, $jwks['keys']);
        $kids = array_column($jwks['keys'], 'kid');
        $this->assertContains($kid1, $kids);
        $this->assertContains($kid2, $kids);

        $keys = JWK::parseKeySet($jwks);
        $decoded = (array) JWT::decode($jwt, $keys);
        $this->assertSame('tok-rs', $decoded['jti']);
        $this->assertSame($kid2, $this->jwtHeaderKid($jwt));

        $admin->run('jwt:key-retire', [$kid1]);
        $jwks2 = (new JwtSigningKeyRepository($this->pdo))->jwks();
        $this->assertCount(1, $jwks2['keys']);
        $this->assertSame($kid2, $jwks2['keys'][0]['kid']);
    }

    public function testEncryptedPrivatePemAtRestRoundTrip(): void
    {
        $secret = 'unit-test-jwt-key-encryption-secret';
        $config = [
            'app_env' => 'prod',
            'broker' => ['base_url' => 'https://auth.example.com'],
            'jwt' => [
                'enabled' => true,
                'hmac_secret' => '',
                'key_encryption_secret' => $secret,
            ],
            'db' => [
                'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('TEST_DB_PORT') ?: '3306'),
                'name' => $this->dbName,
                'user' => getenv('TEST_DB_USER') ?: 'root',
                'password' => getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
                    ? (string) getenv('TEST_DB_PASS')
                    : 'devrootpass',
            ],
        ];

        $admin = AdminCommandRunner::fromPdo($this->pdo, $config);
        $rotated = $admin->run('jwt:key-rotate', []);
        $kid = (string) $rotated['kid'];

        $stmt = $this->pdo->prepare('SELECT private_pem FROM jwt_signing_keys WHERE kid = :kid');
        $stmt->execute(['kid' => $kid]);
        $stored = (string) $stmt->fetchColumn();
        $this->assertStringStartsWith('enc:v1:', $stored);
        $this->assertStringNotContainsString('BEGIN PRIVATE KEY', $stored);

        $record = new AccessToken(
            id: 'tok-enc',
            tokenHash: str_repeat('c', 64),
            clientId: 'svc',
            subjectUserId: null,
            scope: 'kb:read',
            aud: null,
            tenantId: null,
            expiresAt: gmdate('Y-m-d H:i:s', time() + 900),
            revokedAt: null,
            createdAt: gmdate('Y-m-d H:i:s'),
            lastUsedAt: null,
        );
        $jwt = JwtAccessTokenFactory::mint($config, $record, $this->pdo);

        Connection::reset();
        ob_start();
        (new JwksController())->show($config);
        $jwks = json_decode((string) ob_get_clean(), true);
        $decoded = (array) JWT::decode($jwt, JWK::parseKeySet($jwks));
        $this->assertSame('tok-enc', $decoded['jti']);
        $this->assertSame($kid, $this->jwtHeaderKid($jwt));
    }

    public function testProdRotateRequiresEncryptionSecret(): void
    {
        $admin = AdminCommandRunner::fromPdo($this->pdo, [
            'app_env' => 'prod',
            'jwt' => ['key_encryption_secret' => ''],
        ]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JWT_KEY_ENCRYPTION_SECRET');
        $admin->run('jwt:key-rotate', []);
    }

    private function jwtHeaderKid(string $jwt): string
    {
        $parts = explode('.', $jwt);
        $json = json_decode(JWT::urlsafeB64Decode($parts[0]), true);

        return (string) ($json['kid'] ?? '');
    }

    private function rootPdo(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
