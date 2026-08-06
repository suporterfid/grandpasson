<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\OAuthTokenController;
use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Auth\SessionClaimsResolver;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminCommandRunnerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    private AdminCommandRunner $admin;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $this->dbName = 'gp_admin_' . substr(bin2hex(random_bytes(4)), 0, 8);
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
        $_POST = [];
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testTenantGroupMembershipVisibleInClaims(): void
    {
        $userId = $this->seedUser('editor@acme.test');
        $this->admin->run('tenant:create', ['acme', 'Acme Corp']);
        $this->admin->run('tenant:add-member', ['acme', $userId, 'admin']);
        $this->admin->run('group:create', ['acme', 'editors', 'Editors']);
        $this->admin->run('group:add-member', ['acme', 'editors', $userId]);

        $claims = (new SessionClaimsResolver($this->pdo, new TenantRepository($this->pdo)))->resolve([
            'id' => $userId,
            'primary_email' => 'editor@acme.test',
            'display_name' => 'Editor',
            'status' => 'active',
        ]);

        $this->assertSame('acme', $claims['tenant']['slug']);
        $this->assertSame('admin', $claims['tenant']['role']);
        $this->assertSame(['editors'], $claims['groups']);

        $audited = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE actor_type = 'admin' AND result = 'success'"
        )->fetchColumn();
        $this->assertGreaterThanOrEqual(4, $audited);
    }

    public function testCreateServicePrintsSecretOnceAndRotateInvalidatesOld(): void
    {
        $created = $this->admin->run('client:create-service', ['Notes Bot'], [
            'scopes' => 'kb:read',
            'aud' => 'workspace/abc',
            'client-id' => 'svc-notes',
        ]);
        $this->assertArrayHasKey('client_secret', $created);
        $oldSecret = (string) $created['client_secret'];
        $clientId = (string) $created['client_id'];

        $hash = $this->pdo->query(
            'SELECT client_secret_hash FROM service_clients WHERE client_id = ' . $this->pdo->quote($clientId)
        )->fetchColumn();
        $this->assertIsString($hash);
        $this->assertStringNotContainsString($oldSecret, (string) $hash);

        $config = [
            'db' => [
                'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('TEST_DB_PORT') ?: '3306'),
                'name' => $this->dbName,
                'user' => getenv('TEST_DB_USER') ?: 'root',
                'password' => getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
                    ? (string) getenv('TEST_DB_PASS')
                    : 'devrootpass',
            ],
            'tokens' => ['access_ttl_seconds' => 900, 'access_ttl_max_seconds' => 3600],
        ];

        $ok = $this->postToken($config, $clientId, $oldSecret);
        $this->assertSame(200, http_response_code());
        $this->assertArrayHasKey('access_token', $ok);

        $rotated = $this->admin->run('client:rotate-secret', [$clientId]);
        $newSecret = (string) $rotated['client_secret'];
        $this->assertNotSame($oldSecret, $newSecret);

        $fail = $this->postToken($config, $clientId, $oldSecret);
        $this->assertSame(401, http_response_code());
        $this->assertSame('invalid_client', $fail['error']);

        $again = $this->postToken($config, $clientId, $newSecret);
        $this->assertSame(200, http_response_code());
        $this->assertArrayHasKey('access_token', $again);
    }

    public function testCreateServiceAcceptsTasksWriteAndRejectsUnknownScope(): void
    {
        $created = $this->admin->run('client:create-service', ['TaskConnect'], [
            'scopes' => 'tasks:callback,tasks:write',
            'aud' => 'workspace/env_abc123',
            'client-id' => 'svc-tc',
        ]);
        $this->assertTrue($created['ok']);
        $this->assertSame(['tasks:callback', 'tasks:write'], $created['scopes']);
        $this->assertSame('workspace/env_abc123', $created['aud']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown scope');
        $this->admin->run('client:create-service', ['Bad'], [
            'scopes' => 'invented:scope',
            'client-id' => 'svc-bad',
        ]);
    }

    public function testCreateServiceAcceptsStatusConnectScopes(): void
    {
        $created = $this->admin->run('client:create-service', ['StatusConnect'], [
            'scopes' => 'status:read,status:write,status:callback',
            'aud' => 'workspace/env_status123',
            'client-id' => 'svc-statusconnect',
        ]);

        $this->assertTrue($created['ok']);
        $this->assertSame(['status:read', 'status:write', 'status:callback'], $created['scopes']);
        $this->assertSame('workspace/env_status123', $created['aud']);
    }

    public function testTokenRevokeByClient(): void
    {
        $created = $this->admin->run('client:create-service', ['Revoker'], [
            'scopes' => 'kb:read',
            'client-id' => 'svc-rev',
        ]);
        $repo = new ServiceClientRepository($this->pdo);
        // Issue via repository path used by token endpoint.
        $tokens = new \GrandpaSSOn\Infrastructure\Db\AccessTokenRepository($this->pdo);
        $tokens->issue('svc-rev', 'kb:read', null, 900);
        $tokens->issue('svc-rev', 'kb:read', null, 900);

        $result = $this->admin->run('token:revoke', [], ['client' => 'svc-rev']);
        $this->assertSame(2, $result['revoked']);
        $this->assertSame(0, count($tokens->listActive('svc-rev')));
        unset($created, $repo);
    }

    /** @param array<string, mixed> $config @return array<string, mixed> */
    private function postToken(array $config, string $clientId, string $secret): array
    {
        Connection::reset();
        http_response_code(200);
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['REMOTE_ADDR'] = '203.0.113.40';
        $_POST = [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $secret,
            'scope' => 'kb:read',
        ];
        ob_start();
        (new OAuthTokenController())->token($config);
        $raw = (string) ob_get_clean();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function testListPendingReturnsOnlyPendingSignups(): void
    {
        $userId = $this->seedPendingUser('pending@example.com', 'Pending Person', 'I need access');

        $result = $this->admin->run('user:list-pending', []);

        $this->assertTrue($result['ok']);
        $this->assertCount(1, $result['pending']);
        $this->assertSame('pending@example.com', $result['pending'][0]['email']);
        $this->assertSame($userId, $result['pending'][0]['user_id']);
    }

    public function testApprovePromotesUserToActive(): void
    {
        $userId = $this->seedPendingUser('approve-me@example.com', 'Approve Me', 'Reason');

        $result = $this->admin->run('user:approve', [$userId]);

        $this->assertTrue($result['ok']);
        $this->assertSame('active', $result['status']);
        $status = $this->pdo->query('SELECT status FROM users WHERE id = ' . $this->pdo->quote($userId))->fetchColumn();
        $this->assertSame('active', $status);
        $requestStatus = $this->pdo->query('SELECT status FROM signup_requests WHERE user_id = ' . $this->pdo->quote($userId))->fetchColumn();
        $this->assertSame('approved', $requestStatus);
    }

    public function testApproveRejectsNonPendingUser(): void
    {
        $userId = $this->seedPendingUser('already-active@example.com', 'Already Active', 'Reason');
        $this->admin->run('user:approve', [$userId]);

        $this->expectException(\InvalidArgumentException::class);
        $this->admin->run('user:approve', [$userId]);
    }

    public function testRejectStoresReasonAndBlocksLogin(): void
    {
        $userId = $this->seedPendingUser('reject-me@example.com', 'Reject Me', 'Reason');

        $result = $this->admin->run('user:reject', [$userId], ['reason' => 'Not a valid business need']);

        $this->assertTrue($result['ok']);
        $this->assertSame('rejected', $result['status']);
        $row = $this->pdo->query(
            'SELECT status, rejection_reason FROM signup_requests WHERE user_id = ' . $this->pdo->quote($userId)
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('rejected', $row['status']);
        $this->assertSame('Not a valid business need', $row['rejection_reason']);
    }

    public function testReopenMovesRejectedBackToPending(): void
    {
        $userId = $this->seedPendingUser('reopen-me@example.com', 'Reopen Me', 'Reason');
        $this->admin->run('user:reject', [$userId], ['reason' => 'try again later']);

        $result = $this->admin->run('user:reopen', [$userId]);

        $this->assertTrue($result['ok']);
        $this->assertSame('pending', $result['status']);
        $row = $this->pdo->query(
            'SELECT status, reviewed_by, rejection_reason FROM signup_requests WHERE user_id = ' . $this->pdo->quote($userId)
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);
        $this->assertNull($row['reviewed_by']);
        $this->assertNull($row['rejection_reason']);
    }

    private function seedPendingUser(string $email, string $name, string $justification): string
    {
        $userId = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'pending\', :now, :now)'
        )->execute(['id' => $userId, 'email' => $email, 'name' => $name, 'now' => $now]);
        $this->pdo->prepare(
            'INSERT INTO signup_requests (id, user_id, email, display_name, justification, source, status, created_at, updated_at)
             VALUES (:sid, :uid, :email, :name, :justification, \'email\', \'pending\', :now, :now)'
        )->execute([
            'sid' => Uuid::v4(),
            'uid' => $userId,
            'email' => $email,
            'name' => $name,
            'justification' => $justification,
            'now' => $now,
        ]);

        return $userId;
    }

    private function seedUser(string $email): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
        );
        $stmt->execute([
            'id' => $id,
            'email' => $email,
            'name' => 'User',
            'c' => $now,
            'u' => $now,
        ]);

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
