<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpVerifyResult;
use PDO;
use PHPUnit\Framework\TestCase;

final class EmailOtpServiceTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    /** @var array{client_id: string, redirect_uri: string, client_state: string, return_to: ?string, code_challenge: ?string, code_challenge_method: ?string} */
    private array $rpParams = [
        'client_id' => 'cid',
        'redirect_uri' => 'https://app.example/cb',
        'client_state' => 'client-state',
        'return_to' => null,
        'code_challenge' => null,
        'code_challenge_method' => null,
    ];

    protected function setUp(): void
    {
        $this->dbName = 'gp_otp_' . substr(bin2hex(random_bytes(4)), 0, 8);
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

    public function testStartStoresOnlyHashAndVerifyAcceptsMatchingCode(): void
    {
        $svc = new EmailOtpService($this->pdo);
        $started = $svc->start('User@Example.com', $this->rpParams, 600, 5, 6);

        $this->assertNotNull($started['code']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $started['code']);

        $stored = $this->pdo->query('SELECT email, code_hash, consumed FROM email_otp_codes')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($stored);
        $this->assertSame('user@example.com', $stored['email']);
        $this->assertSame(hash('sha256', $started['code']), $stored['code_hash']);
        $this->assertSame(0, (int) $stored['consumed']);
        $this->assertStringNotContainsString($started['code'], (string) json_encode($stored));

        $result = $svc->verify($started['id'], $started['code']);
        $this->assertTrue($result->isOk());
        $this->assertSame('user@example.com', $result->email);
        $this->assertSame('cid', $result->clientId);
        $this->assertSame('https://app.example/cb', $result->redirectUri);
        $this->assertSame('client-state', $result->clientState);
    }

    public function testWrongCodeIncrementsAttemptsAndReportsRemaining(): void
    {
        $svc = new EmailOtpService($this->pdo);
        $started = $svc->start('user@example.com', $this->rpParams, 600, 5, 6);

        $result = $svc->verify($started['id'], '000000' === $started['code'] ? '111111' : '000000');
        $this->assertSame(EmailOtpVerifyResult::WRONG_CODE, $result->status);
        $this->assertSame(4, $result->attemptsRemaining);
    }

    public function testMaxAttemptsLocksTheCode(): void
    {
        $svc = new EmailOtpService($this->pdo);
        $started = $svc->start('user@example.com', $this->rpParams, 600, 2, 6);
        $wrong = $started['code'] === '000000' ? '111111' : '000000';

        $first = $svc->verify($started['id'], $wrong);
        $this->assertSame(EmailOtpVerifyResult::WRONG_CODE, $first->status);

        $second = $svc->verify($started['id'], $wrong);
        $this->assertSame(EmailOtpVerifyResult::LOCKED, $second->status);

        // Even the correct code no longer works once locked (consumed=1).
        $third = $svc->verify($started['id'], $started['code']);
        $this->assertSame(EmailOtpVerifyResult::NOT_FOUND, $third->status);
    }

    public function testExpiredCodeIsRejected(): void
    {
        $svc = new EmailOtpService($this->pdo);
        $started = $svc->start('user@example.com', $this->rpParams, -1, 5, 6);

        $result = $svc->verify($started['id'], $started['code']);
        $this->assertSame(EmailOtpVerifyResult::EXPIRED, $result->status);
    }

    public function testConsumedCodeCannotBeReused(): void
    {
        $svc = new EmailOtpService($this->pdo);
        $started = $svc->start('user@example.com', $this->rpParams, 600, 5, 6);

        $first = $svc->verify($started['id'], $started['code']);
        $this->assertTrue($first->isOk());

        $second = $svc->verify($started['id'], $started['code']);
        $this->assertSame(EmailOtpVerifyResult::NOT_FOUND, $second->status);
    }

    public function testUnknownIdIsNotFound(): void
    {
        $svc = new EmailOtpService($this->pdo);
        $result = $svc->verify('00000000-0000-0000-0000-000000000000', '123456');
        $this->assertSame(EmailOtpVerifyResult::NOT_FOUND, $result->status);
    }

    public function testPerEmailPendingCapThrottlesWithoutRevealingItself(): void
    {
        $svc = new EmailOtpService($this->pdo);
        for ($i = 0; $i < 3; $i++) {
            $started = $svc->start('victim@example.com', $this->rpParams, 600, 5, 6);
            $this->assertNotNull($started['code'], "code {$i} should still be issued");
        }

        // Fourth pending code for the same recipient is throttled, but the
        // caller still gets an id back (never reveal the throttle).
        $throttled = $svc->start('victim@example.com', $this->rpParams, 600, 5, 6);
        $this->assertNull($throttled['code']);
        $this->assertNotSame('', $throttled['id']);
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
