<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\Migrator;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Verifies DB_PREFIX (config('db.prefix'), env GRANDPASSON_DB_PREFIX) actually isolates
 * this app's tables when it shares one MySQL database with sibling apps on shared
 * hosting, instead of being loaded into config and silently ignored.
 */
final class DbPrefixTest extends TestCase
{
    private string $dbName;

    protected function setUp(): void
    {
        Connection::reset();
        $this->dbName = 'gp_prefix_' . substr(bin2hex(random_bytes(4)), 0, 8);

        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Connection::reset();
        try {
            $this->rootPdo()->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        } catch (\Throwable) {
        }
    }

    public function test_migrations_and_repository_queries_use_the_configured_prefix(): void
    {
        $migrationsDir = dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations';
        $expectedCount = count(glob($migrationsDir . '/*.sql') ?: []);

        $pdo = $this->prefixedPdo('sso_');
        $migrator = new Migrator($pdo, $migrationsDir);

        $applied = $migrator->migrate();
        $this->assertCount($expectedCount, $applied);

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        sort($tables);

        // Every table is created under the prefix...
        $this->assertContains('sso_users', $tables);
        $this->assertContains('sso_tenants', $tables);
        $this->assertContains('sso_tenant_members', $tables);
        $this->assertContains('sso_groups', $tables);
        $this->assertContains('sso_group_members', $tables);
        $this->assertContains('sso_sessions', $tables);
        $this->assertContains('sso_schema_migrations', $tables);

        // ...and none are created bare (which would collide with a sibling app's
        // own unprefixed or differently-prefixed tables in the same schema).
        $this->assertNotContains('users', $tables);
        $this->assertNotContains('tenants', $tables);
        $this->assertNotContains('sessions', $tables);
        $this->assertNotContains('schema_migrations', $tables);

        // Re-running is idempotent through the prefixed connection too.
        $this->assertSame([], $migrator->migrate());

        // Repository layer (raw SQL with FKs, including the `groups` reserved word
        // and multi-table JOINs) works transparently against the prefixed tables.
        $tenants = new TenantRepository($pdo);
        $tenant = $tenants->create('acme', 'Acme Corp');
        $this->assertSame('acme', $tenants->findBySlug('acme')?->slug);

        $group = $tenants->createGroup($tenant->id, 'eng', 'Engineering');
        $this->assertSame('eng', $tenants->findGroupByTenantAndSlug($tenant->id, 'eng')?->slug);
        $this->assertSame($group->id, $tenants->findGroupById($group->id)?->id);
    }

    public function test_empty_prefix_preserves_bare_table_names(): void
    {
        $pdo = $this->prefixedPdo('');
        $migrator = new Migrator($pdo, dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations');
        $migrator->migrate();

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertContains('users', $tables);
        $this->assertContains('tenants', $tables);
    }

    private function prefixedPdo(string $prefix): PDO
    {
        return Connection::get([
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_DB_PORT') ?: '3306'),
            'name' => $this->dbName,
            'user' => getenv('TEST_DB_USER') ?: 'root',
            'password' => getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
                ? (string) getenv('TEST_DB_PASS')
                : 'devrootpass',
            'prefix' => $prefix,
        ]);
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
