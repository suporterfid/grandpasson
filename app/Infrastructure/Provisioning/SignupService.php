<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Provisioning;

use GrandpaSSOn\Domain\User;
use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Mail\MailerFactory;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use PDO;

/**
 * Creates the pending-approval user row for self-enrollment. Distinct from
 * UserProvisioner::resolve(), which only ever looks up *existing* users.
 */
final class SignupService
{
    /**
     * @param array{app_env: string, allowed_email_domains: list<string>, mail: array<string, mixed>, broker: array{name: string, base_url: string}, admin_notification_emails: list<string>} $config
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
    ) {
    }

    public function createPending(
        NormalizedIdentity $identity,
        string $displayName,
        string $justification,
        string $source,
    ): User {
        if ($identity->email === null || $identity->email === '' || !$identity->emailVerified) {
            throw new ProviderException('Verified email is required to sign up');
        }

        $email = strtolower($identity->email);
        $displayName = trim($displayName);
        $justification = trim($justification);
        if ($displayName === '') {
            throw new ProviderException('Name is required');
        }
        if ($justification === '') {
            throw new ProviderException('Justification is required');
        }

        if ($this->findByEmail($email) !== null) {
            throw new ProviderException('An account with this email already exists.');
        }

        $this->assertEmailAllowed($email);

        $userId = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
                 VALUES (:id, :email, 1, :name, :avatar, \'pending\', :created, :updated)'
            )->execute([
                'id' => $userId,
                'email' => $email,
                'name' => $displayName,
                'avatar' => $identity->avatarUrl,
                'created' => $now,
                'updated' => $now,
            ]);

            LinkedIdentityWriter::insert($this->pdo, $userId, $identity);

            $this->pdo->prepare(
                'INSERT INTO signup_requests (id, user_id, email, display_name, justification, source, status, created_at, updated_at)
                 VALUES (:id, :user_id, :email, :name, :justification, :source, \'pending\', :created, :updated)'
            )->execute([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'email' => $email,
                'name' => $displayName,
                'justification' => $justification,
                'source' => $source,
                'created' => $now,
                'updated' => $now,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->notifyAdmins($email, $displayName, $justification, $source);

        return new User($userId, $email, true, $displayName, $identity->avatarUrl, 'pending');
    }

    public function assertEmailAllowed(string $email): void
    {
        $domains = $this->config['allowed_email_domains'];
        $env = $this->config['app_env'];

        if ($domains === []) {
            if ($env === 'dev' || $env === 'local') {
                return;
            }
            throw new ProviderException('Signup refused: ALLOWED_EMAIL_DOMAINS is empty outside APP_ENV=dev');
        }

        $host = substr(strrchr($email, '@') ?: '', 1);
        if ($host === '' || !in_array(strtolower($host), $domains, true)) {
            throw new ProviderException('Email domain is not allowed for signup');
        }
    }

    private function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE primary_email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new User(
            (string) $row['id'],
            (string) $row['primary_email'],
            (bool) $row['email_verified'],
            (string) $row['display_name'],
            $row['avatar_url'] !== null ? (string) $row['avatar_url'] : null,
            (string) $row['status'],
        );
    }

    private function notifyAdmins(string $email, string $displayName, string $justification, string $source): void
    {
        $recipients = $this->config['admin_notification_emails'];
        if ($recipients === []) {
            return;
        }
        $brokerName = (string) ($this->config['broker']['name'] ?? 'GrandpaSSOn');
        $adminUrl = rtrim((string) ($this->config['broker']['base_url'] ?? ''), '/') . '/admin';
        $body = "New {$brokerName} signup awaiting approval:\n\n"
            . "Name: {$displayName}\n"
            . "Email: {$email}\n"
            . "Source: {$source}\n"
            . "Justification: {$justification}\n\n"
            . "Review: {$adminUrl}\n";

        try {
            $mailer = (new MailerFactory($this->config['mail']))->make();
            foreach ($recipients as $recipient) {
                $mailer->send($recipient, "{$brokerName}: new signup awaiting approval", $body);
            }
        } catch (\Throwable $e) {
            error_log('signup admin notification mail failed: ' . $e->getMessage());
        }
    }
}
