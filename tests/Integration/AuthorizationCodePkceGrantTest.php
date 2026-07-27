<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\OAuthTokenController;
use GrandpaSSOn\Infrastructure\Auth\AuthCodeService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuthorizationCodePkceGrantTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SERVER['REMOTE_ADDR'] = '198.51.100.88';
        $this->dbName = 'gp_pkce_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $userId = Uuid::v4();
            $now = gmdate('Y-m-d H:i:s');
            $stmt = $root->prepare(
                'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
                 VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
            );
            $stmt->execute([
                'id' => $userId,
                'email' => 'public-rp@example.com',
                'name' => 'Public RP User',
                'c' => $now,
                'u' => $now,
            ]);
            $this->userId = $userId;
            $uris = $root->quote(json_encode(['https://spa.example/cb'], JSON_THROW_ON_ERROR));
            $root->exec(
                "INSERT INTO oauth_clients (client_id, client_secret_hash, name, redirect_uris, type, enabled)
                 VALUES ('spa-app', NULL, 'SPA', {$uris}, 'public', 1)"
            );
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
                'tokens' => ['access_ttl_seconds' => 900, 'access_ttl_max_seconds' => 3600],
            ];
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    private string $userId = '';

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

    public function testPublicClientAuthorizationCodeWithPkceIssuesToken(): void
    {
        $pkce = Pkce::generate();
        $code = (new AuthCodeService($this->pdo))->mint(
            $this->userId,
            'spa-app',
            'https://spa.example/cb',
            $pkce['code_challenge'],
            'S256',
        );

        $payload = $this->postToken([
            'grant_type' => 'authorization_code',
            'client_id' => 'spa-app',
            'code' => $code,
            'redirect_uri' => 'https://spa.example/cb',
            'code_verifier' => $pkce['code_verifier'],
        ]);

        $this->assertSame(200, http_response_code());
        $this->assertArrayHasKey('access_token', $payload);
        $this->assertSame($this->userId, $payload['sub']);
        $this->assertSame('Bearer', $payload['token_type']);

        $row = $this->pdo->query(
            'SELECT oauth_client_id, subject_user_id, client_id FROM access_tokens LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('spa-app', $row['oauth_client_id']);
        $this->assertSame($this->userId, $row['subject_user_id']);
        $this->assertNull($row['client_id']);
    }

    public function testWrongVerifierRejected(): void
    {
        $pkce = Pkce::generate();
        $code = (new AuthCodeService($this->pdo))->mint(
            $this->userId,
            'spa-app',
            'https://spa.example/cb',
            $pkce['code_challenge'],
            'S256',
        );

        $payload = $this->postToken([
            'grant_type' => 'authorization_code',
            'client_id' => 'spa-app',
            'code' => $code,
            'redirect_uri' => 'https://spa.example/cb',
            'code_verifier' => 'not-the-verifier',
        ]);

        $this->assertSame(400, http_response_code());
        $this->assertSame('invalid_grant', $payload['error'] ?? null);
    }

    /** @param array<string, string> $post @return array<string, mixed> */
    private function postToken(array $post): array
    {
        Connection::reset();
        http_response_code(200);
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = $post;
        ob_start();
        (new OAuthTokenController())->token($this->config);
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
