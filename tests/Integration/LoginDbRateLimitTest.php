<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\LoginController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\DbRateLimiter;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class LoginDbRateLimitTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $this->dbName = 'gp_login_rl_' . substr(bin2hex(random_bytes(4)), 0, 8);
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
        $_GET = [];
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testLoginReturns429WhenDbLimited(): void
    {
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
            'app_env' => 'dev',
            'allowed_email_domains' => [],
            'providers' => [],
        ];

        $_SERVER['REMOTE_ADDR'] = '198.51.100.44';
        $limiter = new DbRateLimiter($this->pdo, 15, 300, 900);
        for ($i = 0; $i < 15; $i++) {
            $this->assertTrue($limiter->attempt('login|198.51.100.44'));
        }
        $this->assertFalse($limiter->attempt('login|198.51.100.44'));

        Connection::reset();
        http_response_code(200);
        $_GET = [
            'client_id' => 'any',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 's',
        ];
        ob_start();
        (new LoginController())->start($config, ['provider' => 'google']);
        $raw = (string) ob_get_clean();
        $this->assertSame(429, http_response_code());
        $decoded = json_decode($raw, true);
        $this->assertSame('rate_limited', $decoded['error'] ?? null);
    }

    public function testLoginHonorsConfiguredRateLimit(): void
    {
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
            'app_env' => 'dev',
            'allowed_email_domains' => [],
            'providers' => [],
            'rate_limit' => ['login_max' => 2, 'login_window_seconds' => 60, 'login_lockout_seconds' => 30],
        ];

        $_SERVER['REMOTE_ADDR'] = '198.51.100.45';
        $_GET = [
            'client_id' => 'any',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 's',
        ];

        for ($i = 0; $i < 2; $i++) {
            http_response_code(200);
            ob_start();
            (new LoginController())->start($config, ['provider' => 'google']);
            ob_get_clean();
            $this->assertNotSame(429, http_response_code());
        }

        http_response_code(200);
        ob_start();
        (new LoginController())->start($config, ['provider' => 'google']);
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
