<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Cleanup\AuthCodeCleanup;
use GrandpaSSOn\Infrastructure\Cleanup\SessionCleanup;
use GrandpaSSOn\Infrastructure\Db\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class CleanupJobsTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_clean_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
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

    public function testSessionCleanupDeletesOnlyExpired(): void
    {
        $now = time();
        $this->pdo->exec("INSERT INTO sessions (id, user_id, data, last_access, expires_at) VALUES
            ('alive', NULL, 'x', {$now}, " . ($now + 600) . "),
            ('dead', NULL, 'y', {$now}, " . ($now - 10) . ")");

        $deleted = (new SessionCleanup($this->pdo))->run($now);
        $this->assertSame(1, $deleted);

        $ids = $this->pdo->query('SELECT id FROM sessions ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['alive'], $ids);
    }

    public function testAuthCodeCleanupDeletesExpiredOrConsumedOnly(): void
    {
        $now = time();
        $this->pdo->exec("INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
            VALUES ('u1', 'u@example.com', 1, 'U', NULL, 'active', UTC_TIMESTAMP(), UTC_TIMESTAMP())");
        $this->pdo->exec("INSERT INTO auth_codes (code_hash, user_id, client_id, redirect_uri, expires_at, consumed) VALUES
            ('hash-live', 'u1', 'cid', 'https://app.example/cb', " . ($now + 60) . ", 0),
            ('hash-consumed', 'u1', 'cid', 'https://app.example/cb', " . ($now + 60) . ", 1),
            ('hash-expired', 'u1', 'cid', 'https://app.example/cb', " . ($now - 5) . ", 0)");

        $deleted = (new AuthCodeCleanup($this->pdo))->run($now);
        $this->assertSame(2, $deleted);

        $hashes = $this->pdo->query('SELECT code_hash FROM auth_codes')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['hash-live'], $hashes);
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
