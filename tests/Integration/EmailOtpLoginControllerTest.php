<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\EmailOtpLoginController;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmailOtpLoginControllerTest extends TestCase
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

        $this->dbName = 'gp_otp_ctrl_' . substr(bin2hex(random_bytes(4)), 0, 8);
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
            'email_otp' => [
                'ttl_seconds' => 600,
                'code_length' => 6,
                'max_attempts' => 5,
            ],
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

    public function testFormRejectsInvalidRpParams(): void
    {
        $_GET = ['client_id' => 'cid', 'redirect_uri' => 'https://evil.example/cb', 'state' => 's'];

        ob_start();
        (new EmailOtpLoginController())->form($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertSame(400, http_response_code());
        http_response_code(200);
        $this->assertStringContainsString('Sign-in failed', $body);
    }

    public function testFormRendersEmailInputWithHiddenRpParams(): void
    {
        $_GET = [
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ];

        ob_start();
        (new EmailOtpLoginController())->form($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('name="email"', $body);
        $this->assertStringContainsString('value="cid"', $body);
        $this->assertStringContainsString('value="https://app.example/cb"', $body);
        $this->assertStringContainsString('value="client-state"', $body);
    }

    public function testStartRejectsMissingCsrf(): void
    {
        $_POST = [
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
            'email' => 'user@example.com',
        ];

        ob_start();
        (new EmailOtpLoginController())->start($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertSame(400, http_response_code());
        http_response_code(200);
        $this->assertStringContainsString('Sign-in failed', $body);
    }

    public function testStartRejectsInvalidEmailAndReRendersForm(): void
    {
        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'email' => 'not-an-email',
        ];

        ob_start();
        (new EmailOtpLoginController())->start($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('valid email address', $body);
        $this->assertArrayNotHasKey('email_otp_id', $_SESSION);
    }

    public function testStartIssuesCodeAndVerifyCompletesRoundTripToRp(): void
    {
        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'email' => 'newuser@example.com',
        ];

        ob_start();
        (new EmailOtpLoginController())->start($this->config, []);
        $startBody = (string) ob_get_clean();

        $this->assertStringContainsString('Check your email', $startBody);
        $this->assertArrayHasKey('email_otp_id', $_SESSION);
        $id = $_SESSION['email_otp_id'];

        $row = $this->pdo->query('SELECT code_hash FROM email_otp_codes WHERE id = ' . $this->pdo->quote($id))
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        // The rendered page must never leak the raw code or its hash.
        $this->assertStringNotContainsString((string) $row['code_hash'], $startBody);

        // Drive the verify() HTTP step with a code minted directly via the
        // service (mirrors what the mailer would have received from start(),
        // without sending real mail in the test).
        $started = (new EmailOtpService($this->pdo))->start(
            'newuser@example.com',
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
        $_SESSION['email_otp_id'] = $started['id'];

        $csrf2 = Csrf::token();
        $_POST = ['csrf' => $csrf2, 'code' => $started['code']];

        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        ob_get_clean();

        $this->assertSame(302, http_response_code());
        http_response_code(200);
        $this->assertArrayNotHasKey('email_otp_id', $_SESSION);
        $this->assertArrayHasKey('user_id', $_SESSION);

        $user = $this->pdo->query("SELECT id FROM users WHERE primary_email = 'newuser@example.com'")
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);
        $this->assertSame($user['id'], $_SESSION['user_id']);

        $linked = $this->pdo->query(
            "SELECT provider FROM linked_identities WHERE provider_subject = 'newuser@example.com'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($linked);
        $this->assertSame('email_otp', $linked['provider']);
    }

    public function testVerifyWithWrongCodeRerendersFormWithRemainingAttempts(): void
    {
        $started = (new EmailOtpService($this->pdo))->start(
            'user@example.com',
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
        $_SESSION['email_otp_id'] = $started['id'];
        $wrong = $started['code'] === '000000' ? '111111' : '000000';

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $wrong];

        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('Incorrect code', $body);
        $this->assertStringContainsString('4 attempt', $body);
        $this->assertArrayHasKey('email_otp_id', $_SESSION);
    }

    public function testVerifyFormRedirectsToStartWhenNoPendingSession(): void
    {
        ob_start();
        (new EmailOtpLoginController())->verifyForm($this->config, []);
        ob_get_clean();

        $this->assertSame(302, http_response_code());
        http_response_code(200);
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
