<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\OAuthTokenController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Support\DbRateLimiter;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class DbRateLimiterTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $this->dbName = 'gp_rl_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
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

    public function testDbRateLimiterAllowsThenBlocksThenResets(): void
    {
        $limiter = new DbRateLimiter($this->pdo, 3, 60);
        $now = 1_700_000_000;

        $this->assertTrue($limiter->attempt('oauth_token|1.2.3.4', $now));
        $this->assertTrue($limiter->attempt('oauth_token|1.2.3.4', $now + 1));
        $this->assertTrue($limiter->attempt('oauth_token|1.2.3.4', $now + 2));
        $this->assertFalse($limiter->attempt('oauth_token|1.2.3.4', $now + 3));
        $this->assertTrue($limiter->attempt('oauth_token|9.9.9.9', $now + 3));
        $this->assertTrue($limiter->attempt('oauth_token|1.2.3.4', $now + 61));
    }

    public function testLockoutExtendsBlockBeyondWindow(): void
    {
        // 3 hits / 60s window, then 180s lockout after the limit trips.
        $limiter = new DbRateLimiter($this->pdo, 3, 60, 180);
        $now = 1_700_000_000;

        $this->assertTrue($limiter->attempt('login|1.2.3.4', $now));
        $this->assertTrue($limiter->attempt('login|1.2.3.4', $now + 1));
        $this->assertTrue($limiter->attempt('login|1.2.3.4', $now + 2));
        $this->assertFalse($limiter->attempt('login|1.2.3.4', $now + 3)); // trips lockout
        // Natural window would end at now+60, but lockout keeps blocking until ~now+183
        $this->assertFalse($limiter->attempt('login|1.2.3.4', $now + 70));
        $this->assertTrue($limiter->attempt('login|1.2.3.4', $now + 184));
    }

    public function testOauthTokenEndpointReturns429WhenDbLimited(): void
    {
        (new ServiceClientRepository($this->pdo))->create(
            'svc-rl',
            'RL Client',
            'rl-secret',
            ['kb:read'],
            null,
            true,
        );

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

        $_SERVER['REMOTE_ADDR'] = '203.0.113.99';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $limiter = new DbRateLimiter($this->pdo, 60, 60);
        for ($i = 0; $i < 60; $i++) {
            $this->assertTrue($limiter->attempt('oauth_token|203.0.113.99'));
        }
        $this->assertFalse($limiter->attempt('oauth_token|203.0.113.99'));

        Connection::reset();
        http_response_code(200);
        $_POST = [
            'grant_type' => 'client_credentials',
            'client_id' => 'svc-rl',
            'client_secret' => 'rl-secret',
            'scope' => 'kb:read',
        ];
        ob_start();
        (new OAuthTokenController())->token($config);
        $raw = (string) ob_get_clean();
        $this->assertSame(429, http_response_code());
        $decoded = json_decode($raw, true);
        $this->assertSame('rate_limited', $decoded['error'] ?? null);
    }

    public function testOauthTokenEndpointHonorsConfiguredRateLimit(): void
    {
        (new ServiceClientRepository($this->pdo))->create(
            'svc-rl-cfg',
            'RL Config Client',
            'rl-secret',
            ['kb:read'],
            null,
            true,
        );

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
            'rate_limit' => ['oauth_max' => 2, 'oauth_window_seconds' => 60],
        ];

        $_SERVER['REMOTE_ADDR'] = '203.0.113.100';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = [
            'grant_type' => 'client_credentials',
            'client_id' => 'svc-rl-cfg',
            'client_secret' => 'rl-secret',
            'scope' => 'kb:read',
        ];

        for ($i = 0; $i < 2; $i++) {
            http_response_code(200);
            ob_start();
            (new OAuthTokenController())->token($config);
            ob_get_clean();
            $this->assertSame(200, http_response_code());
        }

        http_response_code(200);
        ob_start();
        (new OAuthTokenController())->token($config);
        $raw = (string) ob_get_clean();
        $this->assertSame(429, http_response_code());
        $decoded = json_decode($raw, true);
        $this->assertSame('rate_limited', $decoded['error'] ?? null);
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
