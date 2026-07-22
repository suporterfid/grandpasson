<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;

final class AuditLoggerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_audit_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            $root->exec((string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/006_create_audit_events.sql'
            ));
            $root->exec((string) file_get_contents(
                dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/011_create_audit_log.sql'
            ));
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

    public function testHashesIpAndOmitsSecrets(): void
    {
        $logger = new AuditLogger($this->pdo);
        $logger->log('logout.success', 'user-1', 'google', '203.0.113.10');

        $row = $this->pdo->query('SELECT * FROM audit_events LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('logout.success', $row['event_type']);
        $this->assertSame('google', $row['provider']);
        $this->assertSame(hash('sha256', '203.0.113.10'), $row['ip_hash']);
        $this->assertSame('user-1', $row['user_id']);
        $encoded = json_encode($row, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('203.0.113.10', $encoded);
        $this->assertStringNotContainsString('secret', $encoded);
    }

    public function testLogDualWritesAuditLogWithInferredSuccess(): void
    {
        $logger = new AuditLogger($this->pdo);
        $logger->log('login.success', 'user-2', 'github', '198.51.100.7');

        $row = $this->pdo->query('SELECT * FROM audit_log LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('subject', $row['actor_type']);
        $this->assertSame('user-2', $row['actor_id']);
        $this->assertSame('login.success', $row['action']);
        $this->assertSame('github', $row['target']);
        $this->assertSame(AuditLogger::RESULT_SUCCESS, $row['result']);
        $this->assertSame(hash('sha256', '198.51.100.7'), $row['ip_hash']);
        $this->assertStringNotContainsString('198.51.100.7', json_encode($row, JSON_THROW_ON_ERROR));
    }

    public function testFailedAuthWritesResultFailure(): void
    {
        $logger = new AuditLogger($this->pdo);
        $logger->log('login.failure', null, 'microsoft', '203.0.113.99');

        $row = $this->pdo->query('SELECT * FROM audit_log WHERE action = \'login.failure\'')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame(AuditLogger::RESULT_FAILURE, $row['result']);
        $this->assertSame('system', $row['actor_type']);
        $this->assertNull($row['actor_id']);

        $events = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM audit_events WHERE event_type = 'login.failure'"
        )->fetchColumn();
        $this->assertSame(1, $events);
    }

    public function testRecordWritesRichShapeAndRejectsSecretLikeValues(): void
    {
        $logger = new AuditLogger($this->pdo);
        $logger->record(
            action: 'token.issued',
            result: AuditLogger::RESULT_SUCCESS,
            actorType: AuditLogger::ACTOR_SERVICE,
            actorId: 'svc-notes',
            target: 'token:abc',
            clientId: 'notes-api',
            ip: '192.0.2.10',
            userAgent: 'NotesBot/1.0',
        );

        $row = $this->pdo->query('SELECT * FROM audit_log WHERE action = \'token.issued\'')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('service', $row['actor_type']);
        $this->assertSame('svc-notes', $row['actor_id']);
        $this->assertSame('notes-api', $row['client_id']);
        $this->assertSame('NotesBot/1.0', $row['user_agent']);
        $this->assertSame(hash('sha256', '192.0.2.10'), $row['ip_hash']);

        $encoded = json_encode($row, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('192.0.2.10', $encoded);
        $this->assertStringNotContainsString('gpat_live_', $encoded);
        $this->assertStringNotContainsString('client_secret', $encoded);

        $this->expectException(InvalidArgumentException::class);
        $logger->record(
            action: 'token.issued',
            result: AuditLogger::RESULT_SUCCESS,
            actorType: AuditLogger::ACTOR_SERVICE,
            target: 'gpat_live_should_not_be_logged',
        );
    }

    public function testRecordIntrospectionFailure(): void
    {
        $logger = new AuditLogger($this->pdo);
        $logger->record(
            action: 'token.introspect',
            result: AuditLogger::RESULT_FAILURE,
            actorType: AuditLogger::ACTOR_SERVICE,
            actorId: 'client-x',
            clientId: 'client-x',
            ip: '203.0.113.50',
        );

        $row = $this->pdo->query('SELECT result, action FROM audit_log LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertSame('failure', $row['result']);
        $this->assertSame('token.introspect', $row['action']);
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
