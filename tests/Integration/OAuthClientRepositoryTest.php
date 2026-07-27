<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class OAuthClientRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        Connection::reset();
        $this->dbName = 'gp_client_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            $root->exec((string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/003_create_oauth_clients.sql'
            ));
            $this->pdo = $root;
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Connection::reset();
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testUpsertHashesSecretAndRoundTrips(): void
    {
        $repo = new OAuthClientRepository($this->pdo);
        $client = $repo->upsert(
            'demo',
            'Demo',
            ['https://app.example/cb'],
            'confidential',
            'plain-secret',
            true,
        );

        $this->assertTrue($client->isConfidential());
        $this->assertNotNull($client->clientSecretHash);
        $this->assertTrue(password_verify('plain-secret', $client->clientSecretHash));
        $this->assertFalse(password_verify('wrong', $client->clientSecretHash));
        $this->assertTrue($client->allowsRedirectUri('https://app.example/cb'));
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
