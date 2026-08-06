<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\AccountNotFoundException;
use GrandpaSSOn\Infrastructure\Providers\AccountPendingException;
use GrandpaSSOn\Infrastructure\Providers\AccountRejectedException;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Provisioning\UserProvisioner;
use GrandpaSSOn\Domain\Uuid;
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

    public function testThrowsAccountNotFoundForUnknownIdentity(): void
    {
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(AccountNotFoundException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'google',
            'sub-unknown',
            'nobody@example.com',
            true,
            'Nobody',
        ));
    }

    public function testRefusesUnverifiedEmailOnUnknownIdentity(): void
    {
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(ProviderException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'microsoft',
            'sub-upn',
            'user@contoso.com',
            false,
            'User',
        ));
    }

    public function testResolvesExistingActiveUserBySubject(): void
    {
        $userId = $this->seedUser('alice@example.com', 'active', 'google', 'g-alice');
        $provisioner = new UserProvisioner($this->pdo);

        $user = $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-alice',
            'alice@example.com',
            true,
            'Alice Updated',
        ));

        $this->assertSame($userId, $user->id);
        $this->assertSame('Alice Updated', $user->displayName);
        $this->assertTrue($user->isActive());
    }

    public function testLinksNewProviderToExistingActiveUserByEmail(): void
    {
        $userId = $this->seedUser('link@example.com', 'active', 'google', 'g-1');
        $provisioner = new UserProvisioner($this->pdo);

        $linked = $provisioner->resolve(new NormalizedIdentity(
            'github',
            'gh-1',
            'link@example.com',
            true,
            'Link',
            null,
            'link',
        ));

        $this->assertSame($userId, $linked->id);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM linked_identities')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testThrowsAccountPendingForPendingUser(): void
    {
        $this->seedUser('pending@example.com', 'pending', 'google', 'g-pending');
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(AccountPendingException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-pending',
            'pending@example.com',
            true,
            'Pending Person',
        ));
    }

    public function testThrowsAccountRejectedForRejectedUser(): void
    {
        $this->seedUser('rejected@example.com', 'rejected', 'google', 'g-rejected');
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(AccountRejectedException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-rejected',
            'rejected@example.com',
            true,
            'Rejected Person',
        ));
    }

    public function testThrowsGenericProviderExceptionForDisabledUser(): void
    {
        $this->seedUser('disabled@example.com', 'disabled', 'google', 'g-disabled');
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('disabled');
        $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-disabled',
            'disabled@example.com',
            true,
            'Disabled Person',
        ));
    }

    /** Seeds a user + one linked identity directly via SQL — resolve() no longer creates accounts. */
    private function seedUser(string $email, string $status, string $provider, string $subject): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, :status, :now, :now)'
        )->execute(['id' => $id, 'email' => $email, 'name' => 'Seed User', 'status' => $status, 'now' => $now]);
        $this->pdo->prepare(
            'INSERT INTO linked_identities (id, user_id, provider, provider_subject, provider_email, provider_username, raw_claims_json, linked_at, last_login_at)
             VALUES (:lid, :uid, :provider, :subject, :email, NULL, :raw, :now, :now)'
        )->execute([
            'lid' => Uuid::v4(),
            'uid' => $id,
            'provider' => $provider,
            'subject' => $subject,
            'email' => $email,
            'raw' => '{}',
            'now' => $now,
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
