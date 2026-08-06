<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Provisioning\SignupService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SignupServiceTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_signup_' . substr(bin2hex(random_bytes(4)), 0, 8);
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

    private function config(array $overrides = []): array
    {
        return array_merge([
            'app_env' => 'dev',
            'allowed_email_domains' => [],
            'mail' => ['transport' => 'sendmail', 'from_address' => 'noreply@example.com', 'from_name' => 'Test'],
            'broker' => ['name' => 'Test Broker', 'base_url' => 'https://sso.example.com'],
            'admin_notification_emails' => [],
        ], $overrides);
    }

    public function testCreatesPendingUserWithSignupRequest(): void
    {
        $service = new SignupService($this->pdo, $this->config());

        $user = $service->createPending(
            new NormalizedIdentity('google', 'g-1', 'alice@example.com', true, 'Alice'),
            'Alice',
            'I need access to review reports.',
            'google',
        );

        $this->assertSame('pending', $user->status);
        $this->assertFalse($user->isActive());

        $row = $this->pdo->query('SELECT status FROM users WHERE id = ' . $this->pdo->quote($user->id))->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);

        $request = $this->pdo->query(
            'SELECT status, source, justification FROM signup_requests WHERE user_id = ' . $this->pdo->quote($user->id)
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $request['status']);
        $this->assertSame('google', $request['source']);
        $this->assertSame('I need access to review reports.', $request['justification']);

        $linkedCount = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM linked_identities WHERE user_id = ' . $this->pdo->quote($user->id)
        )->fetchColumn();
        $this->assertSame(1, $linkedCount);
    }

    public function testRejectsDuplicateEmail(): void
    {
        $service = new SignupService($this->pdo, $this->config());
        $service->createPending(
            new NormalizedIdentity('google', 'g-1', 'dup@example.com', true, 'Dup'),
            'Dup',
            'First request',
            'google',
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('already exists');
        $service->createPending(
            new NormalizedIdentity('github', 'gh-1', 'dup@example.com', true, 'Dup'),
            'Dup',
            'Second request',
            'github',
        );
    }

    public function testRejectsDisallowedDomainOutsideDev(): void
    {
        $service = new SignupService($this->pdo, $this->config([
            'app_env' => 'prod',
            'allowed_email_domains' => ['allowed.com'],
        ]));

        $this->expectException(ProviderException::class);
        $service->createPending(
            new NormalizedIdentity('google', 'g-1', 'someone@notallowed.com', true, 'Someone'),
            'Someone',
            'Please let me in',
            'google',
        );
    }

    public function testAssertEmailAllowedPassesForAllowedDomain(): void
    {
        $service = new SignupService($this->pdo, $this->config([
            'app_env' => 'prod',
            'allowed_email_domains' => ['allowed.com'],
        ]));

        $service->assertEmailAllowed('someone@allowed.com');
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsEmptyJustification(): void
    {
        $service = new SignupService($this->pdo, $this->config());

        $this->expectException(ProviderException::class);
        $service->createPending(
            new NormalizedIdentity('google', 'g-1', 'noreason@example.com', true, 'No Reason'),
            'No Reason',
            '   ',
            'google',
        );
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
