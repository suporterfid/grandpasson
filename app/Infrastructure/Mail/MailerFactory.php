<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Mail;

final class MailerFactory
{
    /**
     * @param array{
     *   transport: string,
     *   from_address: string,
     *   from_name: string,
     *   smtp_host: string,
     *   smtp_port: int,
     *   smtp_username: string,
     *   smtp_password: string,
     *   smtp_encryption: string,
     * } $config
     */
    public function __construct(private readonly array $config)
    {
    }

    public function make(): MailerInterface
    {
        if ($this->config['transport'] === 'smtp') {
            return new SmtpMailer(
                $this->config['smtp_host'],
                $this->config['smtp_port'],
                $this->config['smtp_encryption'],
                $this->config['smtp_username'],
                $this->config['smtp_password'],
                $this->config['from_address'],
                $this->config['from_name'],
            );
        }

        return new SendmailMailer($this->config['from_address'], $this->config['from_name']);
    }
}
