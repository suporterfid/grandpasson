<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\SignupController;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class SignupControllerTest extends TestCase
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

        $this->dbName = 'gp_signup_ctrl_' . substr(bin2hex(random_bytes(4)), 0, 8);
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

    public function testFullEmailSignupFlowCreatesPendingUser(): void
    {
        $_GET = [
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 's',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ];
        ob_start();
        (new SignupController())->form($this->config, []);
        $formBody = (string) ob_get_clean();
        $this->assertStringContainsString('name="justification"', $formBody);

        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 's',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'name' => 'Alice Newperson',
            'email' => 'alice.new@example.com',
            'justification' => 'I need to review shared reports.',
        ];
        ob_start();
        (new SignupController())->start($this->config, []);
        $startBody = (string) ob_get_clean();
        $this->assertStringContainsString('Check your email', $startBody);
        $this->assertArrayHasKey('signup_otp_id', $_SESSION);

        $started = (new EmailOtpService($this->pdo))->start(
            'alice.new@example.com',
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 's',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['signup_otp_id'] = $started['id'];
        $_SESSION['signup_profile'] = ['name' => 'Alice Newperson', 'justification' => 'I need to review shared reports.'];

        $csrf2 = Csrf::token();
        $_POST = ['csrf' => $csrf2, 'code' => $started['code']];
        ob_start();
        (new SignupController())->verify($this->config, []);
        $verifyBody = (string) ob_get_clean();

        $this->assertStringContainsString('Request received', $verifyBody);
        $this->assertArrayNotHasKey('signup_otp_id', $_SESSION);

        $user = $this->pdo->query(
            "SELECT id, status FROM users WHERE primary_email = 'alice.new@example.com'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);
        $this->assertSame('pending', $user['status']);

        $request = $this->pdo->query(
            'SELECT status, justification, source FROM signup_requests WHERE user_id = ' . $this->pdo->quote($user['id'])
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $request['status']);
        $this->assertSame('I need to review shared reports.', $request['justification']);
        $this->assertSame('email', $request['source']);
    }

    public function testSignupStartRejectsDisallowedDomain(): void
    {
        $this->config['app_env'] = 'prod';
        $this->config['allowed_email_domains'] = ['allowed.com'];

        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 's',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'name' => 'Bob Outsider',
            'email' => 'bob@notallowed.com',
            'justification' => 'Let me in please',
        ];
        ob_start();
        (new SignupController())->start($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('not allowed', $body);
        $this->assertArrayNotHasKey('signup_otp_id', $_SESSION);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM email_otp_codes')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDuplicateEmailIsRejectedAtVerify(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :now, :now)'
        )->execute(['id' => \GrandpaSSOn\Domain\Uuid::v4(), 'email' => 'dup@example.com', 'name' => 'Existing', 'now' => $now]);

        $started = (new EmailOtpService($this->pdo))->start(
            'dup@example.com',
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 's',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['signup_otp_id'] = $started['id'];
        $_SESSION['signup_profile'] = ['name' => 'Dup Person', 'justification' => 'Reason'];

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $started['code']];
        ob_start();
        (new SignupController())->verify($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('already exists', $body);
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE primary_email = 'dup@example.com'"
        )->fetchColumn();
        $this->assertSame(1, $count);
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
