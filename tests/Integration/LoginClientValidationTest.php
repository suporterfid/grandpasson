<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\LoginController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class LoginClientValidationTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    private string $tmpEnv;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SESSION = [];
        $_GET = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 254);

        $this->dbName = 'gp_login_' . substr(bin2hex(random_bytes(4)), 0, 8);
        $this->tmpEnv = sys_get_temp_dir() . '/gp-login-env-' . uniqid('', true);

        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;

            $this->writeEnv();
            // Clear process env overrides that ConfigLoader would prefer.
            foreach ([
                'APP_ENV', 'BROKER_BASE_URL', 'BROKER_NAME',
                'SESSION_COOKIE_NAME', 'SESSION_COOKIE_SECURE', 'SESSION_TTL_MINUTES',
                'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
            ] as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
            $this->config = \GrandpaSSOn\Config\ConfigLoader::load($this->tmpEnv);
            Connection::reset();
            $this->pdo = Connection::get($this->config['db']);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SESSION = [];
        $_GET = [];
        if (is_file($this->tmpEnv)) {
            unlink($this->tmpEnv);
        }
        try {
            $root = $this->rootPdo();
            $root->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        } catch (\Throwable) {
        }
    }

    public function testRejectsDisabledClientWith403AndAudit(): void
    {
        $repo = new OAuthClientRepository($this->pdo);
        $repo->upsert(
            'disabled-app',
            'Disabled',
            ['https://app.example/cb'],
            'confidential',
            's3cret',
            false,
        );

        $_GET = [
            'client_id' => 'disabled-app',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
        ];

        $controller = new LoginController();
        ob_start();
        $controller->start($this->config, ['provider' => 'google']);
        $body = (string) ob_get_clean();

        $this->assertSame(403, http_response_code());
        http_response_code(200);
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertSame('disabled_client', $decoded['error']);

        $events = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_events WHERE event_type = 'login.disabled_client'"
        )->fetchColumn();
        $this->assertSame(1, $events);
    }

    public function testRejectsRedirectUriMismatch(): void
    {
        $repo = new OAuthClientRepository($this->pdo);
        $repo->upsert(
            'ok-app',
            'OK',
            ['https://app.example/cb'],
            'confidential',
            's3cret',
            true,
        );

        $_GET = [
            'client_id' => 'ok-app',
            'redirect_uri' => 'https://evil.example/cb',
            'state' => 'client-state',
        ];

        $controller = new LoginController();
        ob_start();
        $controller->start($this->config, ['provider' => 'google']);
        $body = (string) ob_get_clean();

        $this->assertSame(400, http_response_code());
        http_response_code(200);
        $decoded = json_decode($body, true);
        $this->assertSame('invalid_redirect_uri', $decoded['error']);
    }

    private function writeEnv(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = getenv('TEST_DB_PORT') ?: '3306';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        file_put_contents($this->tmpEnv, <<<ENV
APP_ENV=dev
BROKER_BASE_URL=http://localhost:8080
BROKER_NAME=GrandpaSSOn
SESSION_COOKIE_NAME=AUTHSESSID
SESSION_COOKIE_SECURE=false
SESSION_TTL_MINUTES=480
DB_HOST={$host}
DB_PORT={$port}
DB_NAME={$this->dbName}
DB_USER={$user}
DB_PASSWORD={$pass}
ALLOWED_EMAIL_DOMAINS=
MIGRATE_TOKEN=
ENV);
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
