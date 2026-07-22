<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;

final class MigratorTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $tmpDir;
    private string $dbName;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/grandpasson-migrator-' . uniqid('', true);
        mkdir($this->tmpDir);
        $this->dbName = 'gp_migrate_' . substr(bin2hex(random_bytes(4)), 0, 8);

        try {
            $this->pdo = $this->rootPdo();
            $this->pdo->exec(
                'CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $this->pdo->exec('USE `' . $this->dbName . '`');
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available for migrator test: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
                // ignore cleanup failures
            }
            Connection::reset();
        }
        foreach (glob($this->tmpDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    }

    public function testAppliesPendingMigrationsIdempotently(): void
    {
        file_put_contents(
            $this->tmpDir . '/001_a.sql',
            'CREATE TABLE demo (id INT PRIMARY KEY) ENGINE=InnoDB;'
        );
        file_put_contents(
            $this->tmpDir . '/002_b.sql',
            'CREATE TABLE demo2 (id INT PRIMARY KEY) ENGINE=InnoDB;'
        );

        $migrator = new Migrator($this->pdo, $this->tmpDir);

        $first = $migrator->migrate();
        $this->assertSame(['001_a.sql', '002_b.sql'], $first);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());

        $second = $migrator->migrate();
        $this->assertSame([], $second);
        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn());
    }

    public function testStatusReportsPending(): void
    {
        file_put_contents(
            $this->tmpDir . '/001_only.sql',
            'CREATE TABLE t (id INT PRIMARY KEY) ENGINE=InnoDB;'
        );

        $migrator = new Migrator($this->pdo, $this->tmpDir);
        $before = $migrator->status();
        $this->assertSame(['001_only.sql'], $before['pending']);
        $this->assertSame([], $before['applied']);

        $migrator->migrate();
        $after = $migrator->status();
        $this->assertSame([], $after['pending']);
        $this->assertSame(['001_only.sql'], $after['applied']);
    }

    public function testRealMigrationsCreateSixTablesOnEmptyDatabase(): void
    {
        $dir = dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations';
        $migrator = new Migrator($this->pdo, $dir);

        $applied = $migrator->migrate();
        $this->assertCount(6, $applied);
        $this->assertSame([], $migrator->migrate());

        $tables = $this->pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);
        $this->assertContains('users', $tables);
        $this->assertContains('linked_identities', $tables);
        $this->assertContains('oauth_clients', $tables);
        $this->assertContains('sessions', $tables);
        $this->assertContains('auth_codes', $tables);
        $this->assertContains('audit_events', $tables);
        $this->assertContains('schema_migrations', $tables);
    }

    private function rootPdo(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port);
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        return $pdo;
    }
}
