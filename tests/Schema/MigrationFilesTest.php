<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Schema;

use PHPUnit\Framework\TestCase;

final class MigrationFilesTest extends TestCase
{
    private string $appMigrations;
    private string $dockerInit;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 2);
        $this->appMigrations = $root . '/app/Infrastructure/Db/Migrations';
        $this->dockerInit = $root . '/docker/mysql/init';
    }

    public function testBothTreesContainExpectedMigrationFiles(): void
    {
        $app = $this->sqlBasenames($this->appMigrations);
        $docker = $this->sqlBasenames($this->dockerInit);

        self::assertCount(19, $app);
        self::assertSame(
            [
                '001_create_users.sql',
                '002_create_linked_identities.sql',
                '003_create_oauth_clients.sql',
                '004_create_sessions.sql',
                '005_create_auth_codes.sql',
                '006_create_audit_events.sql',
                '007_create_tenants.sql',
                '008_create_tenant_members.sql',
                '009_create_groups.sql',
                '010_create_group_members.sql',
                '011_create_audit_log.sql',
                '012_create_service_clients.sql',
                '013_create_access_tokens.sql',
                '014_alter_access_tokens_for_pats.sql',
                '015_create_rate_limit_counters.sql',
                '016_alter_auth_codes_pkce.sql',
                '017_create_reader_sites_sessions.sql',
                '018_create_jwt_signing_keys.sql',
                '019_create_user_active_tenant.sql',
            ],
            $app
        );
        self::assertSame($app, $docker);
    }

    public function testMigrationTreesAreByteIdentical(): void
    {
        foreach ($this->sqlBasenames($this->appMigrations) as $name) {
            $left = file_get_contents($this->appMigrations . '/' . $name);
            $right = file_get_contents($this->dockerInit . '/' . $name);
            self::assertSame($left, $right, $name . ' drifted between app and docker init');
        }
    }

    public function testCreateTableMigrationsUseInnoDb(): void
    {
        foreach ($this->sqlBasenames($this->appMigrations) as $name) {
            $sql = (string) file_get_contents($this->appMigrations . '/' . $name);
            if (!preg_match('/\bCREATE\s+TABLE\b/i', $sql)) {
                continue;
            }
            self::assertMatchesRegularExpression('/ENGINE\s*=\s*InnoDB/i', $sql, $name);
        }
    }

    public function testAuthCodesStoreHashNotRawCode(): void
    {
        $sql = file_get_contents($this->appMigrations . '/005_create_auth_codes.sql');
        self::assertStringContainsString('code_hash', $sql);
        self::assertDoesNotMatchRegularExpression('/\bcode\s+CHAR\(/i', $sql);
    }

    /** @return list<string> */
    private function sqlBasenames(string $dir): array
    {
        $files = glob($dir . '/*.sql') ?: [];
        $names = array_map('basename', $files);
        sort($names);

        return array_values($names);
    }
}
