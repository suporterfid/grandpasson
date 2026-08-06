<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\EmailOtpLoginController;
use GrandpaSSOn\Http\Controllers\SignupController;
use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class SelfEnrollmentEndToEndTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 254);
        $_GET = [];
        $_POST = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $this->dbName = 'gp_e2e_signup_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $uris = $root->quote(json_encode(['https://app.example/cb'], JSON_THROW_ON_ERROR));
            $root->exec(
                "INSERT INTO oauth_clients (client_id, client_secret_hash, name, redirect_uris, type, enabled)
                 VALUES ('cid', NULL, 'App', {$uris}, 'public', 1)"
            );
            $this->pdo = $root;
            Connection::reset();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }

        $this->config = [
            'app_env' => 'dev',
            'broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost:8080'],
            'db' => [
                'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('TEST_DB_PORT') ?: '3306'),
                'name' => $this->dbName,
                'user' => getenv('TEST_DB_USER') ?: 'root',
                'password' => getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
                    ? (string) getenv('TEST_DB_PASS')
                    : 'devrootpass',
            ],
            'allowed_email_domains' => [],
            'admin_notification_emails' => [],
            'rate_limit' => [
                'email_otp_start_max' => 5,
                'email_otp_start_window_seconds' => 900,
                'email_otp_verify_max' => 10,
                'email_otp_verify_window_seconds' => 900,
            ],
            'mail' => [
                'transport' => 'sendmail',
                'from_address' => 'noreply@example.com',
                'from_name' => 'GrandpaSSOn',
                'smtp_host' => '',
                'smtp_port' => 587,
                'smtp_username' => '',
                'smtp_password' => '',
                'smtp_encryption' => 'tls',
            ],
            'email_otp' => ['ttl_seconds' => 600, 'code_length' => 6, 'max_attempts' => 5],
        ];
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testSignupApproveThenLoginSucceeds(): void
    {
        $email = 'e2e.approve@example.com';
        $this->runSignup($email, 'E2E Approve', 'I need reporting access.');

        $user = $this->pdo->query("SELECT id FROM users WHERE primary_email = '{$email}'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);

        $adminConfig = $this->config;
        $adminConfig['jwt'] = ['key_encryption_secret' => 'test-secret'];
        $result = AdminCommandRunner::fromPdo($this->pdo, $adminConfig)->run('user:approve', [$user['id']]);
        $this->assertTrue($result['ok']);
        $this->assertSame('active', $result['status']);

        $started = (new EmailOtpService($this->pdo))->start(
            $email,
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION = ['email_otp_id' => $started['id']];
        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $started['code']];

        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        ob_get_clean();

        $this->assertSame(302, http_response_code());
        http_response_code(200);
        $this->assertArrayHasKey('user_id', $_SESSION);
        $this->assertSame($user['id'], $_SESSION['user_id']);
    }

    public function testSignupRejectThenLoginBlocked(): void
    {
        $email = 'e2e.reject@example.com';
        $this->runSignup($email, 'E2E Reject', 'Trying my luck.');

        $user = $this->pdo->query("SELECT id FROM users WHERE primary_email = '{$email}'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);

        $adminConfig = $this->config;
        $adminConfig['jwt'] = ['key_encryption_secret' => 'test-secret'];
        $result = AdminCommandRunner::fromPdo($this->pdo, $adminConfig)->run('user:reject', [$user['id']], ['reason' => 'not a valid case']);
        $this->assertTrue($result['ok']);
        $this->assertSame('rejected', $result['status']);

        $started = (new EmailOtpService($this->pdo))->start(
            $email,
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION = ['email_otp_id' => $started['id']];
        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $started['code']];

        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertSame(403, http_response_code());
        http_response_code(200);
        $this->assertStringContainsString('not approved', $body);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    private function runSignup(string $email, string $name, string $justification): void
    {
        $_SESSION = [];
        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'name' => $name,
            'email' => $email,
            'justification' => $justification,
        ];
        ob_start();
        (new SignupController())->start($this->config, []);
        ob_get_clean();

        $started = (new EmailOtpService($this->pdo))->start(
            $email,
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['signup_otp_id'] = $started['id'];
        $_SESSION['signup_profile'] = ['name' => $name, 'justification' => $justification];
        $csrf2 = Csrf::token();
        $_POST = ['csrf' => $csrf2, 'code' => $started['code']];

        ob_start();
        (new SignupController())->verify($this->config, []);
        $body = (string) ob_get_clean();
        $this->assertStringContainsString('Request received', $body);

        $_SESSION = [];
        $_POST = [];
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
