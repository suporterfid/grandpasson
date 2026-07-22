<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\OAuthIntrospectController;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class OAuthIntrospectControllerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    /** @var array<string, mixed> */
    private array $config;
    private AccessTokenRepository $tokens;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.20';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        $this->dbName = 'gp_intro_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            (new ServiceClientRepository($this->pdo))->create(
                'svc-rp',
                'RP Introspector',
                'intro-secret',
                ['kb:read'],
                'workspace/abc',
                true,
            );
            $this->tokens = new AccessTokenRepository($this->pdo);
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

    public function testActiveTokenReturnsClaimsAndTouchesLastUsed(): void
    {
        $issued = $this->tokens->issue('svc-rp', 'kb:read', 'workspace/abc', 900);
        $payload = $this->postIntrospect($issued['token']);

        $this->assertSame(200, http_response_code());
        $this->assertTrue($payload['active']);
        $this->assertSame('svc-rp', $payload['client_id']);
        $this->assertSame('kb:read', $payload['scope']);
        $this->assertSame('workspace/abc', $payload['aud']);
        $this->assertNull($payload['sub']);
        $this->assertNull($payload['tenant']);
        $this->assertSame(
            strtotime($issued['record']->expiresAt . ' UTC'),
            $payload['exp']
        );

        $row = $this->pdo->query(
            'SELECT last_used_at FROM access_tokens WHERE id = ' . $this->pdo->quote($issued['record']->id)
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertNotNull($row['last_used_at']);
    }

    public function testRevokedTokenReturnsExactlyInactive(): void
    {
        $issued = $this->tokens->issue('svc-rp', 'kb:read', 'workspace/abc', 900);
        $this->tokens->revokeById($issued['record']->id);

        $payload = $this->postIntrospect($issued['token']);
        $this->assertSame(200, http_response_code());
        $this->assertSame(['active' => false], $payload);
    }

    public function testUnknownAndExpiredReturnExactlyInactive(): void
    {
        $unknown = $this->postIntrospect('gpat_live_unknownunknownunknownunknownunkn');
        $this->assertSame(['active' => false], $unknown);

        $issued = $this->tokens->issue('svc-rp', 'kb:read', 'workspace/abc', 900);
        $this->pdo->prepare(
            'UPDATE access_tokens SET expires_at = :past WHERE id = :id'
        )->execute([
            'past' => gmdate('Y-m-d H:i:s', time() - 60),
            'id' => $issued['record']->id,
        ]);

        $expired = $this->postIntrospect($issued['token']);
        $this->assertSame(['active' => false], $expired);
    }

    public function testAuthFailureIsAudited(): void
    {
        $payload = $this->postIntrospect('gpat_live_x', 'svc-rp', 'wrong');
        $this->assertSame(401, http_response_code());
        $this->assertSame(['error' => 'invalid_client'], $payload);

        $failures = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'token.introspect' AND result = 'failure'"
        )->fetchColumn();
        $this->assertSame(1, $failures);
    }

    /** @return array<string, mixed> */
    private function postIntrospect(string $token, string $clientId = 'svc-rp', string $secret = 'intro-secret'): array
    {
        Connection::reset();
        http_response_code(200);
        $_POST = [
            'token' => $token,
            'client_id' => $clientId,
            'client_secret' => $secret,
        ];
        ob_start();
        (new OAuthIntrospectController())->introspect($this->config);
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
