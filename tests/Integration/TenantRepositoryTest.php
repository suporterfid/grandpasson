<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Tenant;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\Migrator;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Provisioning\UserProvisioner;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class TenantRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    private TenantRepository $tenants;
    private UserProvisioner $users;

    protected function setUp(): void
    {
        Connection::reset();
        $this->dbName = 'gp_tenant_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->tenants = new TenantRepository($this->pdo);
            $this->users = new UserProvisioner($this->pdo, [
                'app_env' => 'dev',
                'allowed_email_domains' => [],
            ]);
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

    public function testCreateTenantAndMembershipRoundTrip(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->provisionUser('owner-' . $suffix . '@example.com', 'owner-' . $suffix);
        $tenant = $this->tenants->create('acme-' . $suffix, 'Acme Corp ' . $suffix);

        $this->assertSame('acme-' . $suffix, $tenant->slug);
        $this->assertSame('Acme Corp ' . $suffix, $tenant->name);
        $this->assertTrue($tenant->isActive());

        $membership = $this->tenants->addMember($tenant->id, $owner->id, Tenant::ROLE_OWNER);
        $this->assertSame(Tenant::ROLE_OWNER, $membership->role);

        $listed = $this->tenants->listMembershipsForUser($owner->id);
        $this->assertCount(1, $listed);
        $this->assertSame($tenant->id, $listed[0]->tenantId);
        $this->assertSame($tenant->slug, $listed[0]->tenantSlug);
    }

    public function testTenantSlugIsUnique(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $slug = 'dup-' . $suffix;
        $this->tenants->create($slug, 'One');

        $this->expectException(InvalidArgumentException::class);
        $this->tenants->create($slug, 'Two');
    }

    public function testCreateGroupAndMembership(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->provisionUser('member-' . $suffix . '@example.com', 'member-' . $suffix);
        $tenant = $this->tenants->create('org-' . $suffix, 'Org ' . $suffix);
        $this->tenants->addMember($tenant->id, $user->id, Tenant::ROLE_MEMBER);

        $group = $this->tenants->createGroup($tenant->id, 'engineering', 'Engineering');
        $this->assertSame('engineering', $group->slug);

        $this->tenants->addGroupMember($group->id, $user->id);
        $this->assertSame(
            ['engineering'],
            $this->tenants->listGroupSlugsForUserInTenant($tenant->id, $user->id)
        );
    }

    public function testGroupSlugUniquePerTenantOnly(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $a = $this->tenants->create('a-' . $suffix, 'A ' . $suffix);
        $b = $this->tenants->create('b-' . $suffix, 'B ' . $suffix);

        $this->tenants->createGroup($a->id, 'staff', 'Staff');
        $this->tenants->createGroup($b->id, 'staff', 'Staff');

        $this->expectException(InvalidArgumentException::class);
        $this->tenants->createGroup($a->id, 'staff', 'Staff Again');
    }

    public function testInvalidMembershipRoleRejected(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->provisionUser('role-' . $suffix . '@example.com', 'role-' . $suffix);
        $tenant = $this->tenants->create('role-org-' . $suffix, 'Role Org ' . $suffix);

        $this->expectException(InvalidArgumentException::class);
        $this->tenants->addMember($tenant->id, $user->id, 'superuser');
    }

    public function testGroupMemberRequiresTenantMembership(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $outsider = $this->provisionUser('out-' . $suffix . '@example.com', 'out-' . $suffix);
        $tenant = $this->tenants->create('gated-' . $suffix, 'Gated ' . $suffix);
        $group = $this->tenants->createGroup($tenant->id, 'editors', 'Editors');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('tenant member');
        $this->tenants->addGroupMember($group->id, $outsider->id);
    }

    public function testUnknownUserMembershipSurfacesFkNotDuplicate(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $tenant = $this->tenants->create('fk-' . $suffix, 'FK ' . $suffix);
        $missingUserId = '00000000-0000-4000-8000-000000000099';

        try {
            $this->tenants->addMember($tenant->id, $missingUserId, Tenant::ROLE_MEMBER);
            $this->fail('Expected PDOException for missing user FK');
        } catch (InvalidArgumentException $e) {
            $this->fail('FK failure must not be reported as duplicate membership: ' . $e->getMessage());
        } catch (\PDOException $e) {
            $this->assertSame(1452, (int) ($e->errorInfo[1] ?? 0));
        }
    }

    public function testTenancyTablesCoexistWithV0Schema(): void
    {
        $required = [
            'users',
            'linked_identities',
            'oauth_clients',
            'sessions',
            'auth_codes',
            'audit_events',
            'tenants',
            'tenant_members',
            'groups',
            'group_members',
            'audit_log',
        ];

        foreach ($required as $table) {
            $stmt = $this->pdo->query('SHOW TABLES LIKE ' . $this->pdo->quote($table));
            $this->assertNotFalse($stmt);
            $this->assertNotFalse($stmt->fetchColumn(), "Missing table: {$table}");
        }
    }

    public function testMigratorAppliesTenancyOnEmptyDatabase(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';
        $dbName = 'gp_tenancy_' . substr(bin2hex(random_bytes(4)), 0, 8);

        $admin = new PDO(
            sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port),
            $user,
            $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $admin->exec('CREATE DATABASE `' . $dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

        try {
            $admin->exec('USE `' . $dbName . '`');
            $migrator = new Migrator($admin, dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations');
            $applied = $migrator->migrate();
            $this->assertCount(17, $applied);
            $this->assertContains('013_create_access_tokens.sql', $applied);
            $this->assertSame([], $migrator->migrate());

            $repo = new TenantRepository($admin);
            $tenant = $repo->create('fresh-tenant', 'Fresh');
            $this->assertSame('fresh-tenant', $tenant->slug);
            $this->assertNotNull($repo->findBySlug('fresh-tenant'));
        } finally {
            $admin->exec('DROP DATABASE IF EXISTS `' . $dbName . '`');
        }
    }

    private function provisionUser(string $email, string $subject): \GrandpaSSOn\Domain\User
    {
        return $this->users->resolve(new NormalizedIdentity(
            'google',
            $subject,
            $email,
            true,
            'Test User',
        ));
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
