<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Provisioning\SignupService;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use PDO;
use PHPUnit\Framework\TestCase;

final class SignupServiceLocaleTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_signuploc_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
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

    public function testPendingSignupDefaultsToPtBrLocale(): void
    {
        $service = new SignupService($this->pdo, [
            'app_env' => 'dev',
            'allowed_email_domains' => [],
            'mail' => [],
            'broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'https://auth.example.com'],
            'admin_notification_emails' => [],
        ]);

        $identity = new NormalizedIdentity(
            provider: 'google',
            subject: 'g-123',
            email: 'newsignup@example.com',
            emailVerified: true,
            name: 'New Signup',
            avatarUrl: null,
        );

        $user = $service->createPending($identity, 'New Signup', 'I need access', 'google');

        $locale = (string) $this->pdo
            ->query('SELECT locale FROM users WHERE id = ' . $this->pdo->quote($user->id))
            ->fetchColumn();
        $this->assertSame('pt-BR', $locale);
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
