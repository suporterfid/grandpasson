<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Infrastructure\Mail;

use GrandpaSSOn\Infrastructure\Mail\SmtpMailer;
use PHPUnit\Framework\TestCase;

/**
 * Exercises SmtpMailer against a tiny fake SMTP responder. The responder
 * runs in a forked child so both sides of the blocking socket conversation
 * can proceed independently in a single test process; skips gracefully if
 * pcntl/posix aren't available (matches this codebase's "skip if the
 * required infra primitive is unavailable" convention).
 */
final class SmtpMailerTest extends TestCase
{
    protected function setUp(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix not available to run a fake SMTP server');
        }
    }

    public function testSendsMessageThroughAuthenticatedSmtpConversation(): void
    {
        [$server, $port] = $this->listen();

        $pid = pcntl_fork();
        $this->assertNotFalse($pid, 'fork failed');

        if ($pid === 0) {
            $this->runFakeServer($server, [
                ["250 fake.smtp\r\n", false],
                ["334 VXNlcm5hbWU6\r\n", false],
                ["334 UGFzc3dvcmQ6\r\n", false],
                ["235 Authenticated\r\n", false],
                ["250 OK\r\n", false],
                ["250 OK\r\n", false],
                ["354 Go ahead\r\n", false],
                ["250 Queued\r\n", true],
                ["221 Bye\r\n", false],
            ]);
        }

        fclose($server);
        $mailer = new SmtpMailer('127.0.0.1', $port, 'none', 'otp@example.com', 's3cret', 'noreply@example.com', 'GrandpaSSOn');
        $result = $mailer->send('user@example.com', 'Your code', 'Your code is 123456');
        pcntl_waitpid($pid, $status);

        $this->assertTrue($result);
    }

    public function testThrowsWhenServerRejectsRecipient(): void
    {
        [$server, $port] = $this->listen();

        $pid = pcntl_fork();
        $this->assertNotFalse($pid, 'fork failed');

        if ($pid === 0) {
            $this->runFakeServer($server, [
                ["250 fake.smtp\r\n", false],
                ["250 OK\r\n", false],
                ["550 Mailbox unavailable\r\n", false],
            ]);
        }

        fclose($server);
        $mailer = new SmtpMailer('127.0.0.1', $port, 'none', '', '', 'noreply@example.com', 'GrandpaSSOn');

        try {
            $this->expectException(\RuntimeException::class);
            $mailer->send('user@example.com', 'Subject', 'Body');
        } finally {
            pcntl_waitpid($pid, $status);
        }
    }

    public function testRejectsRecipientWithHeaderInjectionBeforeConnecting(): void
    {
        $mailer = new SmtpMailer('127.0.0.1', 1, 'none', '', '', 'noreply@example.com', 'GrandpaSSOn');

        $this->expectException(\InvalidArgumentException::class);
        $mailer->send("user@example.com\r\nBcc: evil@example.com", 'Subject', 'Body');
    }

    /** @return array{0: resource, 1: int} */
    private function listen(): array
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "failed to bind fake SMTP server: {$errstr}");
        $name = (string) stream_socket_get_name($server, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);

        return [$server, $port];
    }

    /**
     * @param resource $server
     * @param list<array{0: string, 1: bool}> $script
     */
    private function runFakeServer($server, array $script): void
    {
        $conn = @stream_socket_accept($server, 5);
        if ($conn !== false) {
            fwrite($conn, "220 fake.smtp ESMTP\r\n");
            foreach ($script as [$response, $isData]) {
                if ($isData) {
                    while (($line = fgets($conn, 4096)) !== false) {
                        if (rtrim($line, "\r\n") === '.') {
                            break;
                        }
                    }
                } else {
                    fgets($conn, 4096);
                }
                fwrite($conn, $response);
            }
            fclose($conn);
        }
        fclose($server);
        posix_kill(posix_getpid(), SIGKILL);
    }
}
