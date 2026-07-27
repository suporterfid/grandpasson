<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Auth\OAuthClientAuthenticator;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class OAuthClientAuthenticatorTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    private OAuthClientAuthenticator $auth;

    protected function setUp(): void
    {
        Connection::reset();
        $this->dbName = 'gp_rp_auth_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $repo = new OAuthClientRepository($this->pdo);
            $repo->upsert('rp-ok', 'OK', ['https://app.example/cb'], 'confidential', 'correct-secret', true);
            $repo->upsert('rp-disabled', 'Disabled', ['https://app.example/cb'], 'confidential', 'secret', false);
            $repo->upsert('rp-public', 'Public', ['https://app.example/cb'], 'public', null, true);
            $this->auth = new OAuthClientAuthenticator($repo);
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

    public function testAcceptsValidConfidentialSecret(): void
    {
        $client = $this->auth->authenticateConfidential('rp-ok', 'correct-secret');
        $this->assertNotNull($client);
        $this->assertSame('rp-ok', $client->clientId);
    }

    public function testUnknownClientSameNullAsBadSecret(): void
    {
        $this->assertNull($this->auth->authenticateConfidential('no-such-client', 'correct-secret'));
        $this->assertNull($this->auth->authenticateConfidential('rp-ok', 'wrong-secret'));
        $this->assertNull($this->auth->authenticateConfidential('rp-disabled', 'secret'));
        $this->assertNull($this->auth->authenticateConfidential('rp-public', 'anything'));
    }

    public function testEmptyInputsRejectedWithoutMatch(): void
    {
        $this->assertNull($this->auth->authenticateConfidential('', 'correct-secret'));
        $this->assertNull($this->auth->authenticateConfidential('rp-ok', ''));
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
