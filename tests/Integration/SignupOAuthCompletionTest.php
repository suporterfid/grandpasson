<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\SignupController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class SignupOAuthCompletionTest extends TestCase
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

        $this->dbName = 'gp_signup_oauth_' . substr(bin2hex(random_bytes(4)), 0, 8);
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

    public function testCompleteRendersPrefilledFormFromPendingSession(): void
    {
        $_SESSION['pending_signup'] = [
            'provider' => 'google',
            'subject' => 'g-sub-1',
            'email' => 'oauth.newuser@example.com',
            'name' => 'OAuth New User',
            'avatar_url' => null,
            'username' => null,
            'raw_claims' => ['sub' => 'g-sub-1'],
        ];

        ob_start();
        (new SignupController())->complete($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('value="OAuth New User"', $body);
        $this->assertStringContainsString('value="oauth.newuser@example.com"', $body);
    }

    public function testCompleteWithoutPendingSessionRedirectsToLogin(): void
    {
        ob_start();
        (new SignupController())->complete($this->config, []);
        ob_get_clean();

        $this->assertSame(302, http_response_code());
        http_response_code(200);
    }

    public function testCompleteSubmitCreatesPendingUserAndClearsSession(): void
    {
        $_SESSION['pending_signup'] = [
            'provider' => 'google',
            'subject' => 'g-sub-2',
            'email' => 'oauth.complete@example.com',
            'name' => 'OAuth Complete',
            'avatar_url' => 'https://example.com/a.png',
            'username' => null,
            'raw_claims' => ['sub' => 'g-sub-2'],
        ];
        $_SESSION['oauth'] = ['provider' => 'google', 'client_id' => 'cid', 'redirect_uri' => 'https://app.example/cb', 'client_state' => 's'];

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'name' => 'OAuth Complete', 'justification' => 'I need dashboard access.'];
        ob_start();
        (new SignupController())->completeSubmit($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('Request received', $body);
        $this->assertArrayNotHasKey('pending_signup', $_SESSION);
        $this->assertArrayNotHasKey('oauth', $_SESSION);

        $user = $this->pdo->query(
            "SELECT id, status FROM users WHERE primary_email = 'oauth.complete@example.com'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);
        $this->assertSame('pending', $user['status']);

        $request = $this->pdo->query(
            'SELECT source FROM signup_requests WHERE user_id = ' . $this->pdo->quote($user['id'])
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('google', $request['source']);

        $linked = $this->pdo->query(
            'SELECT provider, provider_subject FROM linked_identities WHERE user_id = ' . $this->pdo->quote($user['id'])
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('google', $linked['provider']);
        $this->assertSame('g-sub-2', $linked['provider_subject']);
    }

    public function testCompleteSubmitRejectsEmptyJustification(): void
    {
        $_SESSION['pending_signup'] = [
            'provider' => 'github',
            'subject' => 'gh-sub-1',
            'email' => 'oauth.nojustify@example.com',
            'name' => 'No Justify',
            'avatar_url' => null,
            'username' => 'nojustify',
            'raw_claims' => [],
        ];

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'name' => 'No Justify', 'justification' => '  '];
        ob_start();
        (new SignupController())->completeSubmit($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('required', $body);
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE primary_email = 'oauth.nojustify@example.com'"
        )->fetchColumn();
        $this->assertSame(0, $count);
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
