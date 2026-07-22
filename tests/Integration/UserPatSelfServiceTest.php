<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\UserPatController;
use GrandpaSSOn\Infrastructure\Auth\OpaqueToken;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserPatSelfServiceTest extends TestCase
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
        $_SERVER['REMOTE_ADDR'] = '203.0.113.50';
        $_SERVER['CONTENT_TYPE'] = 'application/json';
        $this->dbName = 'gp_mepat_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
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
        $_SESSION = [];
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testCreateListRevokeForAuthenticatedSubject(): void
    {
        $userId = $this->seedUser('self@example.com');
        $_SESSION['user_id'] = $userId;
        $csrf = Csrf::token();

        http_response_code(200);
        $this->withJsonBody([
            'csrf' => $csrf,
            'scopes' => 'kb:read,tenant:read',
            'label' => 'My agent',
            'aud' => 'workspace/x',
            'ttl_days' => 30,
        ]);
        ob_start();
        (new UserPatController())->create($this->config);
        $created = json_decode((string) ob_get_clean(), true);
        $this->assertSame(201, http_response_code());
        $this->assertTrue($created['ok'] ?? false);
        $plaintext = (string) $created['token'];
        $this->assertTrue(OpaqueToken::hasExpectedShape($plaintext));
        $tokenId = (string) $created['token_id'];

        $hash = (string) $this->pdo->query(
            'SELECT token_hash FROM access_tokens WHERE id = ' . $this->pdo->quote($tokenId)
        )->fetchColumn();
        $this->assertSame(OpaqueToken::hash($plaintext), $hash);

        http_response_code(200);
        ob_start();
        (new UserPatController())->list($this->config);
        $listed = json_decode((string) ob_get_clean(), true);
        $this->assertSame(200, http_response_code());
        $this->assertSame(1, $listed['count']);
        $this->assertSame('My agent', $listed['tokens'][0]['label']);
        $this->assertArrayNotHasKey('token', $listed['tokens'][0]);
        $this->assertArrayNotHasKey('token_hash', $listed['tokens'][0]);

        http_response_code(200);
        $this->withJsonBody(['csrf' => Csrf::token()]);
        ob_start();
        (new UserPatController())->revoke($this->config, ['id' => $tokenId]);
        $revoked = json_decode((string) ob_get_clean(), true);
        $this->assertSame(200, http_response_code());
        $this->assertSame(1, $revoked['revoked']);

        ob_start();
        (new UserPatController())->list($this->config);
        $after = json_decode((string) ob_get_clean(), true);
        $this->assertSame(0, $after['count']);
    }

    public function testCannotRevokeAnotherSubjectsPat(): void
    {
        $owner = $this->seedUser('owner@example.com');
        $attacker = $this->seedUser('attacker@example.com');
        $_SESSION['user_id'] = $owner;
        $this->withJsonBody([
            'csrf' => Csrf::token(),
            'scopes' => 'kb:read',
            'ttl_days' => 7,
        ]);
        ob_start();
        (new UserPatController())->create($this->config);
        $created = json_decode((string) ob_get_clean(), true);
        $tokenId = (string) $created['token_id'];

        $_SESSION = ['user_id' => $attacker];
        http_response_code(200);
        $this->withJsonBody(['csrf' => Csrf::token()]);
        ob_start();
        (new UserPatController())->revoke($this->config, ['id' => $tokenId]);
        $raw = (string) ob_get_clean();
        $this->assertSame(404, http_response_code());
        $decoded = json_decode($raw, true);
        $this->assertSame('not_found', $decoded['error'] ?? null);
    }

    public function testUnauthenticatedRejected(): void
    {
        $_SESSION = [];
        http_response_code(200);
        ob_start();
        (new UserPatController())->list($this->config);
        $this->assertSame(401, http_response_code());
        ob_get_clean();
    }

    /** @param array<string, mixed> $body */
    private function withJsonBody(array $body): void
    {
        $json = json_encode($body, JSON_THROW_ON_ERROR);
        // Http::readBody reads php://input which we cannot easily stub; use $_POST + form content type.
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = $body;
        unset($json);
    }

    private function seedUser(string $email): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
        );
        $stmt->execute([
            'id' => $id,
            'email' => $email,
            'name' => 'Self PAT',
            'c' => $now,
            'u' => $now,
        ]);

        return $id;
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
