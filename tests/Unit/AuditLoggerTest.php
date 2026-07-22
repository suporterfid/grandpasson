<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_audit_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            $root->exec((string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/006_create_audit_events.sql'
            ));
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

    public function testHashesIpAndOmitsSecrets(): void
    {
        $logger = new AuditLogger($this->pdo);
        $logger->log('logout.success', 'user-1', 'google', '203.0.113.10');

        $row = $this->pdo->query('SELECT * FROM audit_events LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('logout.success', $row['event_type']);
        $this->assertSame('google', $row['provider']);
        $this->assertSame(hash('sha256', '203.0.113.10'), $row['ip_hash']);
        $this->assertSame('user-1', $row['user_id']);
        $encoded = json_encode($row, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('203.0.113.10', $encoded);
        $this->assertStringNotContainsString('secret', $encoded);
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
