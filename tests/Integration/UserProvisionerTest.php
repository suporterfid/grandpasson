<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Provisioning\UserProvisioner;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserProvisionerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_prov_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            Connection::reset();
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
            Connection::reset();
        }
    }

    public function testCreatesUserInDevWithEmptyAllowlist(): void
    {
        $provisioner = new UserProvisioner($this->pdo, [
            'app_env' => 'dev',
            'allowed_email_domains' => [],
        ]);

        $user = $provisioner->resolve(new NormalizedIdentity(
            'google',
            'sub-1',
            'alice@example.com',
            true,
            'Alice',
            null,
            null,
            ['sub' => 'sub-1'],
        ));

        $this->assertSame('alice@example.com', $user->primaryEmail);
        $this->assertTrue($user->isActive());

        // Second login finds by provider subject.
        $again = $provisioner->resolve(new NormalizedIdentity(
            'google',
            'sub-1',
            'alice@example.com',
            true,
            'Alice Updated',
            'https://example.com/a.png',
        ));
        $this->assertSame($user->id, $again->id);
        $this->assertSame('Alice Updated', $again->displayName);
    }

    public function testRefusesUnverifiedEmail(): void
    {
        $provisioner = new UserProvisioner($this->pdo, [
            'app_env' => 'dev',
            'allowed_email_domains' => [],
        ]);

        $this->expectException(ProviderException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'microsoft',
            'sub-upn',
            'user@contoso.com',
            false,
            'User',
        ));
    }

    public function testRefusesAutoCreateOutsideDevWithoutAllowlist(): void
    {
        $provisioner = new UserProvisioner($this->pdo, [
            'app_env' => 'prod',
            'allowed_email_domains' => [],
        ]);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('ALLOWED_EMAIL_DOMAINS');
        $provisioner->resolve(new NormalizedIdentity(
            'github',
            '99',
            'bob@example.com',
            true,
            'Bob',
            null,
            'bob',
        ));
    }

    public function testLinksByVerifiedEmail(): void
    {
        $provisioner = new UserProvisioner($this->pdo, [
            'app_env' => 'dev',
            'allowed_email_domains' => [],
        ]);

        $first = $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-1',
            'link@example.com',
            true,
            'Link',
        ));

        $linked = $provisioner->resolve(new NormalizedIdentity(
            'github',
            'gh-1',
            'link@example.com',
            true,
            'Link',
            null,
            'link',
        ));

        $this->assertSame($first->id, $linked->id);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM linked_identities')->fetchColumn();
        $this->assertSame(2, $count);
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
