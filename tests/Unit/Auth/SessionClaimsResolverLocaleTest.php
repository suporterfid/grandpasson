<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Auth;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Auth\SessionClaimsResolver;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SessionClaimsResolverLocaleTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        Connection::reset();
        $this->dbName = 'gp_claimsloc_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 3) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testResolveIncludesLocaleFromUserArray(): void
    {
        $userId = Uuid::v4();
        $resolver = new SessionClaimsResolver($this->pdo, new TenantRepository($this->pdo));

        $claims = $resolver->resolve([
            'id' => $userId,
            'primary_email' => 'claims@example.com',
            'display_name' => 'Claims Test',
            'status' => 'active',
            'locale' => 'en',
        ]);

        $this->assertSame('en', $claims['locale']);
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
