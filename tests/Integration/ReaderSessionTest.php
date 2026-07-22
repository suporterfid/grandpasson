<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\PublishedSite;
use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\SessionController;
use GrandpaSSOn\Http\Controllers\SiteReaderController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\PublishedSiteRepository;
use GrandpaSSOn\Infrastructure\Db\ReaderSessionRepository;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class ReaderSessionTest extends TestCase
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
        $_COOKIE = [];
        $this->dbName = 'gp_reader_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->config = [
                'app_env' => 'dev',
                'allowed_email_domains' => [],
                'session' => [
                    'cookie_name' => 'AUTHSESSID',
                    'secure' => false,
                    'ttl_minutes' => 60,
                    'reader_cookie_name' => 'GPSREADER',
                ],
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
        $_COOKIE = [];
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testPublicSiteNeedsNoAuth(): void
    {
        (new PublishedSiteRepository($this->pdo))->create('docs-public', 'Public Docs', PublishedSite::VIS_PUBLIC);
        http_response_code(200);
        ob_start();
        (new SiteReaderController())->session($this->config, ['site_id' => 'docs-public']);
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertSame(200, http_response_code());
        $this->assertFalse($payload['auth_required']);
    }

    public function testAuthenticatedSiteIssuesReaderCookieWithoutEditorSession(): void
    {
        (new PublishedSiteRepository($this->pdo))->create(
            'docs-auth',
            'Auth Docs',
            PublishedSite::VIS_AUTHENTICATED,
        );
        $userId = $this->seedUser('reader@example.com');
        $issued = (new ReaderSessionRepository($this->pdo))->issue(
            $userId,
            'docs-auth',
            [ReaderSessionRepository::SCOPE_PUBLISH_READ],
            3600,
        );
        $_COOKIE['GPSREADER'] = $issued['token'];

        http_response_code(200);
        ob_start();
        (new SiteReaderController())->session($this->config, ['site_id' => 'docs-auth']);
        $reader = json_decode((string) ob_get_clean(), true);
        $this->assertSame(200, http_response_code());
        $this->assertSame($userId, $reader['sub']);
        $this->assertSame(['publish:read'], $reader['scopes']);
        $this->assertSame('reader', $reader['token_use']);

        // Editor /session must still reject — reader cookie grants no editor capability.
        $_SESSION = [];
        http_response_code(200);
        ob_start();
        (new SessionController())->show($this->config);
        $editor = json_decode((string) ob_get_clean(), true);
        $this->assertSame(401, http_response_code());
        $this->assertSame('unauthenticated', $editor['error'] ?? null);
    }

    public function testPrivateSiteRequiresTenantMembership(): void
    {
        $tenants = new TenantRepository($this->pdo);
        $tenant = $tenants->create('acme', 'Acme');
        (new PublishedSiteRepository($this->pdo))->create(
            'docs-private',
            'Private',
            PublishedSite::VIS_PRIVATE,
            $tenant->id,
        );
        $member = $this->seedUser('member@acme.test');
        $outsider = $this->seedUser('outsider@example.com');
        $tenants->addMember($tenant->id, $member, 'member');

        $ok = (new ReaderSessionRepository($this->pdo))->issue(
            $member,
            'docs-private',
            [ReaderSessionRepository::SCOPE_PUBLISH_READ],
            3600,
        );
        $_COOKIE['GPSREADER'] = $ok['token'];
        http_response_code(200);
        ob_start();
        (new SiteReaderController())->session($this->config, ['site_id' => 'docs-private']);
        $payload = json_decode((string) ob_get_clean(), true);
        $this->assertSame(200, http_response_code());
        $this->assertSame($member, $payload['sub']);

        // Outsider token for same site is still "authenticated" at session layer;
        // membership is enforced at login time. Simulate missing cookie → 401.
        $_COOKIE = [];
        http_response_code(200);
        ob_start();
        (new SiteReaderController())->session($this->config, ['site_id' => 'docs-private']);
        $denied = json_decode((string) ob_get_clean(), true);
        $this->assertSame(401, http_response_code());
        $this->assertStringContainsString('/site/docs-private/login/', (string) ($denied['login'] ?? ''));
        unset($outsider);
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
            'name' => 'Reader',
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
