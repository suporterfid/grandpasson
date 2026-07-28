<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Mail;

/**
 * Minimal hand-rolled SMTP client (no Composer dependency) for hosts where
 * local sendmail delivers poorly (R17). Speaks EHLO/STARTTLS/AUTH
 * LOGIN/MAIL/RCPT/DATA over a raw stream socket.
 */
final class SmtpMailer implements MailerInterface
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $encryption, // none|tls|ssl
        private readonly string $username,
        private readonly string $password,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function send(string $to, string $subject, string $textBody): bool
    {
        $this->assertNoHeaderInjection($to, 'to');
        $this->assertNoHeaderInjection($subject, 'subject');

        $scheme = $this->encryption === 'ssl' ? 'ssl://' : '';
        $stream = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds
        );
        if ($stream === false) {
            throw new \RuntimeException("SMTP connect to {$this->host}:{$this->port} failed: {$errstr} ({$errno})");
        }

        try {
            $this->expect($stream, 220);
            $this->command($stream, 'EHLO ' . $this->localHostname(), 250);

            if ($this->encryption === 'tls') {
                $this->command($stream, 'STARTTLS', 220);
                if (!stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('SMTP STARTTLS negotiation failed');
                }
                $this->command($stream, 'EHLO ' . $this->localHostname(), 250);
            }

            if ($this->username !== '') {
                $this->command($stream, 'AUTH LOGIN', 334);
                $this->command($stream, base64_encode($this->username), 334);
                $this->command($stream, base64_encode($this->password), 235);
            }

            $this->command($stream, 'MAIL FROM:<' . $this->fromAddress . '>', 250);
            $this->command($stream, 'RCPT TO:<' . $to . '>', 250);
            $this->command($stream, 'DATA', 354);

            $headers = 'From: ' . $this->encodeFrom() . "\r\n"
                . 'To: <' . $to . ">\r\n"
                . 'Subject: ' . $subject . "\r\n"
                . "Content-Type: text/plain; charset=utf-8\r\n";
            fwrite($stream, $headers . "\r\n" . $this->dotStuff($textBody) . "\r\n.\r\n");
            $this->expect($stream, 250);

            $this->command($stream, 'QUIT', 221);

            return true;
        } finally {
            fclose($stream);
        }
    }

    /** @param resource $stream */
    private function command($stream, string $line, int $expectedCode): string
    {
        fwrite($stream, $line . "\r\n");

        return $this->expect($stream, $expectedCode);
    }

    /** @param resource $stream */
    private function expect($stream, int $expectedCode): string
    {
        $lastLine = '';
        do {
            $line = fgets($stream, 1024);
            if ($line === false) {
                throw new \RuntimeException('SMTP connection closed unexpectedly');
            }
            $lastLine = $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($lastLine, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException('Unexpected SMTP response: ' . trim($lastLine));
        }

        return $lastLine;
    }

    private function dotStuff(string $body): string
    {
        return (string) preg_replace('/^\./m', '..', $body);
    }

    private function encodeFrom(): string
    {
        $name = str_replace(["\r", "\n"], '', $this->fromName);
        $address = str_replace(["\r", "\n"], '', $this->fromAddress);

        return $name !== '' ? "{$name} <{$address}>" : $address;
    }

    private function localHostname(): string
    {
        $host = gethostname();

        return $host !== false && $host !== '' ? $host : 'localhost';
    }

    private function assertNoHeaderInjection(string $value, string $field): void
    {
        if (preg_match('/[\r\n]/', $value) === 1) {
            throw new \InvalidArgumentException("Refusing to send mail: {$field} contains a line break");
        }
    }
}
