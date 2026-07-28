<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Mail;

interface MailerInterface
{
    public function send(string $to, string $subject, string $textBody): bool;
}
