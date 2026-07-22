<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Session\MysqlSessionHandler;
use PDO;
use PHPUnit\Framework\TestCase;

final class MysqlSessionHandlerTest extends TestCase
{
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        Connection::reset();
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $name = getenv('TEST_DB_NAME') ?: 'grandpasson';
        $user = getenv('TEST_DB_USER') ?: 'grandpasson';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devpass';

        try {
            $this->pdo = Connection::get([
                'host' => $host,
                'port' => $port,
                'name' => $name,
                'user' => $user,
                'password' => $pass,
            ]);
            $this->pdo->query('SELECT 1 FROM sessions LIMIT 1');
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available for session handler test: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        Connection::reset();
    }

    public function testWriteReadDestroyRoundTrip(): void
    {
        $handler = new MysqlSessionHandler($this->pdo, 3600);
        $id = 'testsession' . bin2hex(random_bytes(8));
        $payload = 'user_id|s:1:"1";';

        $this->assertTrue($handler->open('', 'AUTHSESSID'));
        $this->assertTrue($handler->write($id, $payload));
        $this->assertSame($payload, $handler->read($id));
        $this->assertTrue($handler->destroy($id));
        $this->assertSame('', $handler->read($id));
        $this->assertTrue($handler->close());
    }

    public function testSkipsRewriteWhenPayloadUnchanged(): void
    {
        $handler = new MysqlSessionHandler($this->pdo, 3600);
        $id = 'samepayload' . bin2hex(random_bytes(8));
        $payload = 'x|s:1:"1";';

        $handler->write($id, $payload);
        $handler->read($id);
        $this->assertTrue($handler->write($id, $payload));

        $row = $this->pdo->prepare('SELECT data FROM sessions WHERE id = :id');
        $row->execute(['id' => $id]);
        $data = $row->fetchColumn();
        $this->assertSame($payload, is_string($data) ? $data : (string) $data);
        $handler->destroy($id);
    }

    public function testGcRemovesExpiredRows(): void
    {
        $handler = new MysqlSessionHandler($this->pdo, 60);
        $id = 'gcsession' . bin2hex(random_bytes(8));

        $handler->write($id, 'x|s:1:"1";');
        $this->pdo->prepare('UPDATE sessions SET expires_at = :ts WHERE id = :id')->execute([
            'ts' => time() - 10,
            'id' => $id,
        ]);

        $removed = $handler->gc(60);
        $this->assertGreaterThanOrEqual(1, $removed);
        $this->assertSame('', $handler->read($id));
    }
}
