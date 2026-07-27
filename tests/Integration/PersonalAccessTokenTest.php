<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\AccessToken;
use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\OAuthIntrospectController;
use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Auth\OpaqueToken;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class PersonalAccessTokenTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    private AdminCommandRunner $admin;
    private AccessTokenRepository $tokens;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $this->dbName = 'gp_pat_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->admin = AdminCommandRunner::fromPdo($this->pdo);
            $this->tokens = new AccessTokenRepository($this->pdo);
            (new ServiceClientRepository($this->pdo))->create(
                'svc-intro',
                'Introspector',
                'intro-secret',
                ['kb:read'],
                null,
                true,
            );
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

    public function testPatCreateListRevokeAndIntrospectTouchesLastUsed(): void
    {
        $userId = $this->seedUser('agent@example.com');
        $created = $this->admin->run('pat:create', [$userId], [
            'scopes' => 'kb:read,tenant:read',
            'label' => 'Notes agent',
            'aud' => 'workspace/abc',
            'ttl-days' => '30',
        ]);

        $this->assertTrue($created['ok']);
        $this->assertSame(AccessToken::KIND_PAT, $created['kind']);
        $plaintext = (string) $created['token'];
        $this->assertTrue(OpaqueToken::hasExpectedShape($plaintext));

        $hash = (string) $this->pdo->query(
            'SELECT token_hash FROM access_tokens WHERE id = ' . $this->pdo->quote((string) $created['token_id'])
        )->fetchColumn();
        $this->assertSame(OpaqueToken::hash($plaintext), $hash);
        $this->assertStringNotContainsString($plaintext, $hash);

        $listed = $this->admin->run('pat:list', [], ['subject' => $userId]);
        $this->assertSame(1, $listed['count']);
        $this->assertSame('Notes agent', $listed['tokens'][0]['label']);
        $this->assertNull($listed['tokens'][0]['last_used_at']);

        $payload = $this->postIntrospect($plaintext);
        $this->assertTrue($payload['active']);
        $this->assertSame($userId, $payload['sub']);
        $this->assertNull($payload['client_id']);
        $this->assertSame('kb:read tenant:read', $payload['scope']);
        $this->assertSame('workspace/abc', $payload['aud']);
        $this->assertSame('pat', $payload['token_use']);
        $this->assertSame('pat', $payload['token_type']);

        $after = $this->tokens->findById((string) $created['token_id']);
        $this->assertNotNull($after);
        $this->assertNotNull($after->lastUsedAt);

        $revoked = $this->admin->run('pat:revoke', [(string) $created['token_id']]);
        $this->assertSame(1, $revoked['revoked']);

        $inactive = $this->postIntrospect($plaintext);
        $this->assertSame(['active' => false], $inactive);
        $this->assertSame(0, $this->admin->run('pat:list', [], ['subject' => $userId])['count']);
    }

    public function testPatRevokeBySubjectLeavesServiceTokens(): void
    {
        $userId = $this->seedUser('multi@example.com');
        $this->admin->run('pat:create', [$userId], ['scopes' => 'kb:read', 'ttl-days' => '7']);
        $this->admin->run('pat:create', [$userId], ['scopes' => 'kb:write', 'ttl-days' => '7']);
        $this->tokens->issue('svc-intro', 'kb:read', null, 900);

        $result = $this->admin->run('pat:revoke', [], ['subject' => $userId]);
        $this->assertSame(2, $result['revoked']);
        $this->assertSame(0, count($this->tokens->listActive(null, $userId, AccessToken::KIND_PAT)));
        $this->assertSame(1, count($this->tokens->listActive('svc-intro', null, AccessToken::KIND_ACCESS)));
    }

    /** @return array<string, mixed> */
    private function postIntrospect(string $token): array
    {
        Connection::reset();
        http_response_code(200);
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_SERVER['REMOTE_ADDR'] = '198.51.100.77';
        $_POST = [
            'token' => $token,
            'client_id' => 'svc-intro',
            'client_secret' => 'intro-secret',
        ];
        ob_start();
        (new OAuthIntrospectController())->introspect($this->config);
        $raw = (string) ob_get_clean();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
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
            'name' => 'PAT User',
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
