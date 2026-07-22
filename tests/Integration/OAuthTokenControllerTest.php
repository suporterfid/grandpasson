<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\OAuthTokenController;
use GrandpaSSOn\Infrastructure\Auth\OpaqueToken;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class OAuthTokenControllerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.10';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        $this->dbName = 'gp_oauth_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            (new ServiceClientRepository($this->pdo))->create(
                'svc-notes',
                'Notes Agent',
                'correct-secret',
                ['kb:read', 'tasks:callback'],
                'workspace/abc',
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
                'tokens' => [
                    'access_ttl_seconds' => 900,
                    'access_ttl_max_seconds' => 3600,
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

    public function testIssuesOpaqueTokenForAllowedScope(): void
    {
        $payload = $this->postToken([
            'grant_type' => 'client_credentials',
            'client_id' => 'svc-notes',
            'client_secret' => 'correct-secret',
            'scope' => 'kb:read',
        ]);

        $this->assertSame(200, http_response_code());
        $this->assertSame('Bearer', $payload['token_type']);
        $this->assertSame('kb:read', $payload['scope']);
        $this->assertSame('workspace/abc', $payload['aud']);
        $this->assertLessThanOrEqual(3600, $payload['expires_in']);
        $this->assertTrue(OpaqueToken::hasExpectedShape((string) $payload['access_token']));

        $row = $this->pdo->query('SELECT token_hash, scope FROM access_tokens LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(OpaqueToken::hash((string) $payload['access_token']), $row['token_hash']);
        $this->assertStringNotContainsString((string) $payload['access_token'], (string) json_encode($row));
    }

    public function testIssuesTasksWriteWithEnvironmentAudience(): void
    {
        (new ServiceClientRepository($this->pdo))->create(
            'svc-taskconnect',
            'TaskConnect',
            'tc-secret',
            ['tasks:callback', 'tasks:write'],
            'workspace/env_abc123',
            true,
        );

        $payload = $this->postToken([
            'grant_type' => 'client_credentials',
            'client_id' => 'svc-taskconnect',
            'client_secret' => 'tc-secret',
            'scope' => 'tasks:write',
        ]);

        $this->assertSame(200, http_response_code());
        $this->assertSame('tasks:write', $payload['scope']);
        $this->assertSame('workspace/env_abc123', $payload['aud']);
        $this->assertTrue(OpaqueToken::hasExpectedShape((string) $payload['access_token']));
    }

    public function testRejectsDisallowedScopeWithoutIssuing(): void
    {
        $payload = $this->postToken([
            'grant_type' => 'client_credentials',
            'client_id' => 'svc-notes',
            'client_secret' => 'correct-secret',
            'scope' => 'kb:write',
        ]);

        $this->assertSame(400, http_response_code());
        $this->assertSame('invalid_scope', $payload['error']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM access_tokens')->fetchColumn());
    }

    public function testBadSecretReturnsInvalidClientAndAudits(): void
    {
        $payload = $this->postToken([
            'grant_type' => 'client_credentials',
            'client_id' => 'svc-notes',
            'client_secret' => 'wrong-secret',
        ]);

        $this->assertSame(401, http_response_code());
        $this->assertSame(['error' => 'invalid_client'], $payload);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM access_tokens')->fetchColumn());

        $failures = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'token.issue' AND result = 'failure'"
        )->fetchColumn();
        $this->assertSame(1, $failures);
    }

    public function testUnknownClientSameErrorShape(): void
    {
        $payload = $this->postToken([
            'grant_type' => 'client_credentials',
            'client_id' => 'no-such-client',
            'client_secret' => 'anything-long',
        ]);

        $this->assertSame(401, http_response_code());
        $this->assertSame(['error' => 'invalid_client'], $payload);
    }

    public function testRejectsAudienceOutsideClientDefault(): void
    {
        $payload = $this->postToken([
            'grant_type' => 'client_credentials',
            'client_id' => 'svc-notes',
            'client_secret' => 'correct-secret',
            'scope' => 'kb:read',
            'audience' => 'workspace/victim',
        ]);

        $this->assertSame(400, http_response_code());
        $this->assertSame('invalid_request', $payload['error']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM access_tokens')->fetchColumn());
    }

    /** @param array<string, string> $fields @return array<string, mixed> */
    private function postToken(array $fields): array
    {
        Connection::reset();
        http_response_code(200);
        $_POST = $fields;
        ob_start();
        (new OAuthTokenController())->token($this->config);
        $raw = (string) ob_get_clean();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
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
