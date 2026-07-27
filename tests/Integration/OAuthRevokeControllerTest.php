<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\OAuthIntrospectController;
use GrandpaSSOn\Http\Controllers\OAuthRevokeController;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class OAuthRevokeControllerTest extends TestCase
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
        $_SERVER['REMOTE_ADDR'] = '198.51.100.30';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';

        $this->dbName = 'gp_revoke_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $clients = new ServiceClientRepository($this->pdo);
            $clients->create('svc-a', 'A', 'secret-a', ['kb:read'], 'workspace/a', true);
            $clients->create('svc-b', 'B', 'secret-b', ['kb:read'], 'workspace/b', true);
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

    public function testRevokeThenIntrospectInactive(): void
    {
        $issued = $this->tokens->issue('svc-a', 'kb:read', 'workspace/a', 900);
        $revoke = $this->postRevoke($issued['token'], 'svc-a', 'secret-a');
        $this->assertSame(200, http_response_code());
        $this->assertSame(['revoked' => true], $revoke);

        $intro = $this->postIntrospect($issued['token'], 'svc-a', 'secret-a');
        $this->assertSame(['active' => false], $intro);
    }

    public function testDoubleRevokeAndUnknownStill200(): void
    {
        $issued = $this->tokens->issue('svc-a', 'kb:read', 'workspace/a', 900);
        $this->assertSame(200, http_response_code());
        $first = $this->postRevoke($issued['token'], 'svc-a', 'secret-a');
        $second = $this->postRevoke($issued['token'], 'svc-a', 'secret-a');
        $unknown = $this->postRevoke('gpat_live_unknownunknownunknownunknownunkn', 'svc-a', 'secret-a');

        $this->assertSame(['revoked' => true], $first);
        $this->assertSame(['revoked' => true], $second);
        $this->assertSame(['revoked' => true], $unknown);
    }

    public function testAdminRevokeByClientClearsAllActive(): void
    {
        $a1 = $this->tokens->issue('svc-a', 'kb:read', 'workspace/a', 900);
        $a2 = $this->tokens->issue('svc-a', 'kb:read', 'workspace/a', 900);
        $b1 = $this->tokens->issue('svc-b', 'kb:read', 'workspace/b', 900);

        $count = $this->tokens->revokeByClientId('svc-a');
        $this->assertSame(2, $count);

        $this->assertSame(['active' => false], $this->postIntrospect($a1['token'], 'svc-a', 'secret-a'));
        $this->assertSame(['active' => false], $this->postIntrospect($a2['token'], 'svc-a', 'secret-a'));
        $this->assertTrue($this->postIntrospect($b1['token'], 'svc-b', 'secret-b')['active']);
    }

    public function testCannotRevokeAnotherClientsTokenViaHttp(): void
    {
        $issued = $this->tokens->issue('svc-a', 'kb:read', 'workspace/a', 900);
        $this->postRevoke($issued['token'], 'svc-b', 'secret-b');
        $intro = $this->postIntrospect($issued['token'], 'svc-a', 'secret-a');
        $this->assertTrue($intro['active']);
    }

    /** @return array<string, mixed> */
    private function postRevoke(string $token, string $clientId, string $secret): array
    {
        Connection::reset();
        http_response_code(200);
        $_POST = [
            'token' => $token,
            'client_id' => $clientId,
            'client_secret' => $secret,
        ];
        ob_start();
        (new OAuthRevokeController())->revoke($this->config);
        $raw = (string) ob_get_clean();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function postIntrospect(string $token, string $clientId, string $secret): array
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
