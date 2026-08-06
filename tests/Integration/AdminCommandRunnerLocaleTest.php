<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminCommandRunnerLocaleTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    private AdminCommandRunner $admin;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $this->dbName = 'gp_adminloc_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->admin = AdminCommandRunner::fromPdo($this->pdo);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testSetLocaleUpdatesUserAndAudits(): void
    {
        $userId = $this->seedUser('adminloc@example.com');

        $result = $this->admin->run('user:set-locale', [$userId, 'en']);

        $this->assertTrue($result['ok']);
        $this->assertSame('en', $result['locale']);

        $stored = (string) $this->pdo
            ->query('SELECT locale FROM users WHERE id = ' . $this->pdo->quote($userId))
            ->fetchColumn();
        $this->assertSame('en', $stored);
    }

    public function testSetLocaleRejectsUnsupportedValue(): void
    {
        $userId = $this->seedUser('adminbad@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->admin->run('user:set-locale', [$userId, 'es']);
    }

    public function testSetLocaleRejectsUnknownUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->admin->run('user:set-locale', [Uuid::v4(), 'en']);
    }

    private function seedUser(string $email): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
        );
        $stmt->execute(['id' => $id, 'email' => $email, 'name' => 'Admin Locale Test', 'c' => $now, 'u' => $now]);

        return $id;
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
