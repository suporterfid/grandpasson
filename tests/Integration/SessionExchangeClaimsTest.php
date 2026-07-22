<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Tenant;
use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\SessionExchangeController;
use GrandpaSSOn\Infrastructure\Auth\AuthCodeService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class SessionExchangeClaimsTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $this->dbName = 'gp_xclaim_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->config = [
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
            (new OAuthClientRepository($this->pdo))->upsert(
                'rp-app',
                'RP',
                ['https://app.example/cb'],
                'confidential',
                's3cret',
                true,
            );
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SESSION = [];
        unset($GLOBALS['__php_input_override']);
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testAdminEditorsFixtureReturnsClaims(): void
    {
        $userId = $this->seedUser('admin@acme.test', 'Acme Admin', 'google');
        $tenants = new TenantRepository($this->pdo);
        $tenant = $tenants->create('acme', 'Acme Corp');
        $tenants->addMember($tenant->id, $userId, Tenant::ROLE_ADMIN);
        $group = $tenants->createGroup($tenant->id, 'editors', 'Editors');
        $tenants->addGroupMember($group->id, $userId);

        $payload = $this->exchangeForUser($userId);

        $this->assertSame($userId, $payload['id']);
        $this->assertSame('admin@acme.test', $payload['email']);
        $this->assertSame('Acme Admin', $payload['display_name']);
        $this->assertSame('active', $payload['status']);

        $this->assertSame($userId, $payload['subject']['id']);
        $this->assertSame('google', $payload['subject']['idp']);
        $this->assertSame('acme', $payload['tenant']['slug']);
        $this->assertSame('admin', $payload['tenant']['role']);
        $this->assertCount(1, $payload['tenants']);
        $this->assertSame(['editors'], $payload['groups']);
        $this->assertContains('tenant:read', $payload['scopes']);
    }

    public function testNoMembershipReturnsNullTenantAndEmptyLists(): void
    {
        $userId = $this->seedUser('solo@example.com', 'Solo', 'github');
        $payload = $this->exchangeForUser($userId);

        $this->assertSame(200, http_response_code());
        $this->assertSame($userId, $payload['id']);
        $this->assertNull($payload['tenant']);
        $this->assertSame([], $payload['tenants']);
        $this->assertSame([], $payload['groups']);
        $this->assertSame('github', $payload['subject']['idp']);
    }

    public function testMultiTenantPicksLowestSlug(): void
    {
        $userId = $this->seedUser('multi@example.com', 'Multi', null);
        $tenants = new TenantRepository($this->pdo);
        $zeta = $tenants->create('zeta', 'Zeta');
        $alpha = $tenants->create('alpha', 'Alpha');
        $tenants->addMember($zeta->id, $userId, Tenant::ROLE_MEMBER);
        $tenants->addMember($alpha->id, $userId, Tenant::ROLE_OWNER);

        $payload = $this->exchangeForUser($userId);
        $this->assertSame('alpha', $payload['tenant']['slug']);
        $this->assertSame('owner', $payload['tenant']['role']);
        $this->assertCount(2, $payload['tenants']);
        $this->assertNull($payload['subject']['idp']);
    }

    /** @return array<string, mixed> */
    private function exchangeForUser(string $userId): array
    {
        Connection::reset();
        $code = (new AuthCodeService($this->pdo))->mint($userId, 'rp-app', 'https://app.example/cb');
        $body = json_encode([
            'code' => $code,
            'client_id' => 'rp-app',
            'client_secret' => 's3cret',
            'redirect_uri' => 'https://app.example/cb',
        ], JSON_THROW_ON_ERROR);

        // Http::readBody reads php://input; override via temp stream is awkward — use $_POST with form type.
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = [
            'code' => $code,
            'client_id' => 'rp-app',
            'client_secret' => 's3cret',
            'redirect_uri' => 'https://app.example/cb',
        ];

        ob_start();
        (new SessionExchangeController())->exchange($this->config);
        $raw = (string) ob_get_clean();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function seedUser(string $email, string $name, ?string $provider): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :created, :updated)'
        );
        $stmt->execute([
            'id' => $id,
            'email' => $email,
            'name' => $name,
            'created' => $now,
            'updated' => $now,
        ]);

        if ($provider !== null) {
            $link = $this->pdo->prepare(
                'INSERT INTO linked_identities
                 (id, user_id, provider, provider_subject, provider_email, provider_username, raw_claims_json, linked_at, last_login_at)
                 VALUES (:id, :user_id, :provider, :subject, :email, NULL, NULL, :linked, :login)'
            );
            $link->execute([
                'id' => Uuid::v4(),
                'user_id' => $id,
                'provider' => $provider,
                'subject' => 'sub-' . bin2hex(random_bytes(4)),
                'email' => $email,
                'linked' => $now,
                'login' => $now,
            ]);
        }

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
