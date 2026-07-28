<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Infrastructure\Mail;

use GrandpaSSOn\Infrastructure\Mail\SendmailMailer;
use PHPUnit\Framework\TestCase;

final class SendmailMailerTest extends TestCase
{
    public function testSendsViaInjectedTransportWithFromHeader(): void
    {
        $captured = null;
        $mailer = new SendmailMailer(
            'noreply@example.com',
            'GrandpaSSOn',
            function (string $to, string $subject, string $body, string $headers) use (&$captured): bool {
                $captured = ['to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers];

                return true;
            }
        );

        $result = $mailer->send('user@example.com', 'Your code', 'Your code is 123456');

        $this->assertTrue($result);
        $this->assertSame('user@example.com', $captured['to']);
        $this->assertSame('Your code', $captured['subject']);
        $this->assertStringContainsString('123456', $captured['body']);
        $this->assertStringContainsString('From: GrandpaSSOn <noreply@example.com>', $captured['headers']);
        $this->assertStringContainsString('Content-Type: text/plain', $captured['headers']);
    }

    public function testRejectsToAddressWithHeaderInjection(): void
    {
        $mailer = new SendmailMailer('noreply@example.com', 'GrandpaSSOn', fn () => true);

        $this->expectException(\InvalidArgumentException::class);
        $mailer->send("user@example.com\r\nBcc: evil@example.com", 'Subject', 'Body');
    }

    public function testRejectsSubjectWithHeaderInjection(): void
    {
        $mailer = new SendmailMailer('noreply@example.com', 'GrandpaSSOn', fn () => true);

        $this->expectException(\InvalidArgumentException::class);
        $mailer->send('user@example.com', "Subject\r\nBcc: evil@example.com", 'Body');
    }
}
