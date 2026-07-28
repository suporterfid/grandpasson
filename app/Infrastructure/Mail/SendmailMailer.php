<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Mail;

/**
 * Wraps PHP's native mail() (local sendmail/MTA) — zero dependency, the
 * default transport (R17). The transport is injectable so tests can spy on
 * it without a real MTA.
 */
final class SendmailMailer implements MailerInterface
{
    /** @var callable(string, string, string, string): bool */
    private $transport;

    public function __construct(
        private readonly string $fromAddress,
        private readonly string $fromName,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? mail(...);
    }

    public function send(string $to, string $subject, string $textBody): bool
    {
        $this->assertNoHeaderInjection($to, 'to');
        $this->assertNoHeaderInjection($subject, 'subject');

        $headers = 'From: ' . $this->encodeFrom() . "\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n";

        return ($this->transport)($to, $subject, $textBody, $headers);
    }

    private function encodeFrom(): string
    {
        $name = str_replace(["\r", "\n"], '', $this->fromName);
        $address = str_replace(["\r", "\n"], '', $this->fromAddress);

        return $name !== '' ? "{$name} <{$address}>" : $address;
    }

    private function assertNoHeaderInjection(string $value, string $field): void
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new \InvalidArgumentException("Refusing to send mail: {$field} contains a line break");
        }
    }
}
