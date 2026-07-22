<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\AdminUiController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminHttpTest extends TestCase
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
        $_SERVER['REMOTE_ADDR'] = '198.51.100.50';
        $this->dbName = 'gp_admhttp_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->config = [
                'broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost'],
                'admin_api_token' => 'test-admin-token',
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
        $_SESSION = [];
        $_POST = [];
        unset($_SERVER['HTTP_X_ADMIN_TOKEN'], $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['CONTENT_TYPE']);
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testUiDisabledWithoutTokenConfig(): void
    {
        $config = $this->config;
        $config['admin_api_token'] = '';
        ob_start();
        (new AdminUiController())->index($config);
        $html = (string) ob_get_clean();
        $this->assertSame(403, http_response_code());
        $this->assertStringContainsString('ADMIN_API_TOKEN', $html);
    }

    public function testApiUnauthorizedWithoutHeader(): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        http_response_code(200);
        // php://input is empty in CLI — simulate via $_POST path with empty token
        $_POST = [
            'verb' => 'tenant:create',
            'args' => ['acme', 'Acme'],
            'flags' => [],
        ];
        unset($_SERVER['CONTENT_TYPE']);
        ob_start();
        (new AdminUiController())->api($this->config);
        $raw = (string) ob_get_clean();
        $this->assertSame(401, http_response_code());
        $this->assertSame('unauthorized', json_decode($raw, true)['error'] ?? null);
    }

    public function testApiCreatesTenantWithAdminToken(): void
    {
        $_SERVER['HTTP_X_ADMIN_TOKEN'] = 'test-admin-token';
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        http_response_code(200);
        $_POST = [
            'verb' => 'tenant:create',
            'args' => ['acme', 'Acme Corp'],
            'flags' => [],
        ];
        // Form-urlencoded readBody returns $_POST, but args/flags need to be arrays —
        // Http::readBody for non-JSON returns $_POST as-is.
        ob_start();
        (new AdminUiController())->api($this->config);
        $raw = (string) ob_get_clean();
        $decoded = json_decode($raw, true);
        $this->assertSame(200, http_response_code());
        $this->assertTrue($decoded['ok'] ?? false);
        $this->assertSame('acme', $decoded['slug'] ?? null);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM tenants WHERE slug = 'acme'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testUiRendersWhenConfigured(): void
    {
        http_response_code(200);
        ob_start();
        (new AdminUiController())->index($this->config);
        $html = (string) ob_get_clean();
        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('tenant:create', $html);
        $this->assertStringContainsString('/admin/api', $html);
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
