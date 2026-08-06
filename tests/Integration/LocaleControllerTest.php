<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\LocaleController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class LocaleControllerTest extends TestCase
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
        $_SERVER['REMOTE_ADDR'] = '203.0.113.60';
        $this->dbName = 'gp_melocale_' . substr(bin2hex(random_bytes(4)), 0, 8);
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

    public function testUnauthenticatedGetRejected(): void
    {
        http_response_code(200);
        ob_start();
        (new LocaleController())->show($this->config);
        $this->assertSame(401, http_response_code());
        ob_get_clean();
    }

    public function testGetReturnsDefaultLocaleForNewUser(): void
    {
        $userId = $this->seedUser('reader@example.com');
        $_SESSION['user_id'] = $userId;

        http_response_code(200);
        ob_start();
        (new LocaleController())->show($this->config);
        $body = json_decode((string) ob_get_clean(), true);

        $this->assertSame(200, http_response_code());
        $this->assertSame('pt-BR', $body['locale']);
        $this->assertNotEmpty($body['csrf']);
    }

    public function testSetWithSupportedLocalePersistsAndReturnsIt(): void
    {
        $userId = $this->seedUser('setter@example.com');
        $_SESSION['user_id'] = $userId;
        $this->withJsonBody(['csrf' => Csrf::token(), 'locale' => 'en']);

        http_response_code(200);
        ob_start();
        (new LocaleController())->set($this->config);
        $body = json_decode((string) ob_get_clean(), true);

        $this->assertSame(200, http_response_code());
        $this->assertTrue($body['ok']);
        $this->assertSame('en', $body['locale']);

        $stored = (string) $this->pdo
            ->query('SELECT locale FROM users WHERE id = ' . $this->pdo->quote($userId))
            ->fetchColumn();
        $this->assertSame('en', $stored);
    }

    public function testSetWithUnsupportedLocaleRejected(): void
    {
        $userId = $this->seedUser('bad@example.com');
        $_SESSION['user_id'] = $userId;
        $this->withJsonBody(['csrf' => Csrf::token(), 'locale' => 'es']);

        http_response_code(200);
        ob_start();
        (new LocaleController())->set($this->config);
        $body = json_decode((string) ob_get_clean(), true);

        $this->assertSame(400, http_response_code());
        $this->assertSame('invalid_request', $body['error']);
    }

    public function testSetWithoutCsrfRejected(): void
    {
        $userId = $this->seedUser('nocsrf@example.com');
        $_SESSION['user_id'] = $userId;
        $this->withJsonBody(['locale' => 'en']);

        http_response_code(200);
        ob_start();
        (new LocaleController())->set($this->config);
        $this->assertSame(403, http_response_code());
        ob_get_clean();
    }

    /** @param array<string, mixed> $body */
    private function withJsonBody(array $body): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = $body;
    }

    private function seedUser(string $email): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
        );
        $stmt->execute(['id' => $id, 'email' => $email, 'name' => 'Locale Controller Test', 'c' => $now, 'u' => $now]);

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
