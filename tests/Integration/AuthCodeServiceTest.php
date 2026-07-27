<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Auth\AuthCodeService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthCodeServiceTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_codes_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            // Seed user + confidential client
            $root->exec("INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
                VALUES ('u1', 'u@example.com', 1, 'U', NULL, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $hash = password_hash('s3cret', PASSWORD_DEFAULT);
            $uris = $root->quote(json_encode(['https://app.example/cb'], JSON_THROW_ON_ERROR));
            $root->exec("INSERT INTO oauth_clients (client_id, client_secret_hash, name, redirect_uris, type, enabled)
                VALUES ('cid', " . $root->quote($hash) . ", 'App', {$uris}, 'confidential', 1)");
            $this->pdo = $root;
            Connection::reset();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
            Connection::reset();
        }
    }

    public function testMintAndAtomicConsume(): void
    {
        $svc = new AuthCodeService($this->pdo);
        $raw = $svc->mint('u1', 'cid', 'https://app.example/cb');

        $stored = $this->pdo->query('SELECT code_hash, consumed FROM auth_codes')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($stored);
        $this->assertSame(hash('sha256', $raw), $stored['code_hash']);
        $this->assertSame(0, (int) $stored['consumed']);
        $this->assertStringNotContainsString($raw, (string) json_encode($stored));

        $userId = $svc->consume($raw, 'cid', 'https://app.example/cb');
        $this->assertSame('u1', $userId);

        $again = $svc->consume($raw, 'cid', 'https://app.example/cb');
        $this->assertNull($again);
    }

    public function testRejectsRedirectMismatch(): void
    {
        $svc = new AuthCodeService($this->pdo);
        $raw = $svc->mint('u1', 'cid', 'https://app.example/cb');
        $this->assertNull($svc->consume($raw, 'cid', 'https://evil.example/cb'));
    }

    public function testPkceBoundCodeRequiresMatchingVerifier(): void
    {
        $pkce = \GrandpaSSOn\Infrastructure\Providers\Pkce::generate();
        $svc = new AuthCodeService($this->pdo);
        $raw = $svc->mint(
            'u1',
            'cid',
            'https://app.example/cb',
            $pkce['code_challenge'],
            $pkce['code_challenge_method'],
        );

        $this->assertNull($svc->consume($raw, 'cid', 'https://app.example/cb'));
        $this->assertNull($svc->consume($raw, 'cid', 'https://app.example/cb', 'bad-verifier'));
        $this->assertSame('u1', $svc->consume($raw, 'cid', 'https://app.example/cb', $pkce['code_verifier']));
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
