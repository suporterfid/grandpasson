<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Provisioning;

use GrandpaSSOn\Domain\User;
use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use PDO;

final class UserProvisioner
{
    /**
     * @param array{app_env: string, allowed_email_domains: list<string>} $config
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
    ) {
    }

    public function resolve(NormalizedIdentity $identity): User
    {
        $existing = $this->findByProviderSubject($identity->provider, $identity->subject);
        if ($existing !== null) {
            if (!$existing->isActive()) {
                throw new ProviderException('User account is disabled');
            }
            $this->syncProfileAndTouch($existing, $identity);

            return $this->findById($existing->id) ?? $existing;
        }

        if ($identity->email === null || $identity->email === '' || !$identity->emailVerified) {
            throw new ProviderException('Verified email is required to provision or link an account');
        }

        $email = strtolower($identity->email);
        $byEmail = $this->findByEmail($email);
        if ($byEmail !== null) {
            if (!$byEmail->isActive()) {
                throw new ProviderException('User account is disabled');
            }
            $this->linkIdentity($byEmail->id, $identity);
            $this->syncProfileAndTouch($byEmail, $identity);

            return $this->findById($byEmail->id) ?? $byEmail;
        }

        $this->assertMayAutoCreate($email);

        return $this->createUser($identity, $email);
    }

    private function assertMayAutoCreate(string $email): void
    {
        $domains = $this->config['allowed_email_domains'];
        $env = $this->config['app_env'];

        if ($domains === []) {
            if ($env === 'dev' || $env === 'local') {
                return;
            }
            throw new ProviderException('Auto-create refused: ALLOWED_EMAIL_DOMAINS is empty outside APP_ENV=dev');
        }

        $host = substr(strrchr($email, '@') ?: '', 1);
        if ($host === '' || !in_array(strtolower($host), $domains, true)) {
            throw new ProviderException('Email domain is not allowed for auto-provisioning');
        }
    }

    private function createUser(NormalizedIdentity $identity, string $email): User
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $name = $identity->name ?: ($identity->username ?: $email);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
                 VALUES (:id, :email, 1, :name, :avatar, \'active\', :created, :updated)'
            );
            $stmt->execute([
                'id' => $id,
                'email' => $email,
                'name' => $name,
                'avatar' => $identity->avatarUrl,
                'created' => $now,
                'updated' => $now,
            ]);
            $this->linkIdentity($id, $identity);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return new User($id, $email, true, $name, $identity->avatarUrl, 'active');
    }

    private function linkIdentity(string $userId, NormalizedIdentity $identity): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO linked_identities
             (id, user_id, provider, provider_subject, provider_email, provider_username, raw_claims_json, linked_at, last_login_at)
             VALUES (:id, :user_id, :provider, :subject, :email, :username, :raw, :linked_at, :last_login)'
        );
        $stmt->execute([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'provider' => $identity->provider,
            'subject' => $identity->subject,
            'email' => $identity->email,
            'username' => $identity->username,
            'raw' => json_encode($identity->rawClaims, JSON_THROW_ON_ERROR),
            'linked_at' => $now,
            'last_login' => $now,
        ]);
    }

    private function syncProfileAndTouch(User $user, NormalizedIdentity $identity): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $name = $identity->name ?: $user->displayName;
        $avatar = $identity->avatarUrl ?? $user->avatarUrl;

        $stmt = $this->pdo->prepare(
            'UPDATE users SET display_name = :name, avatar_url = :avatar, updated_at = :updated WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'avatar' => $avatar,
            'updated' => $now,
            'id' => $user->id,
        ]);

        // Provider email change: flag for review — do not silently switch primary_email.
        if (
            $identity->emailVerified
            && $identity->email !== null
            && strtolower($identity->email) !== strtolower($user->primaryEmail)
        ) {
            // Audit is left to the caller; touch linked identity email only.
        }

        $touch = $this->pdo->prepare(
            'UPDATE linked_identities
             SET provider_email = :email, provider_username = :username, raw_claims_json = :raw, last_login_at = :last_login
             WHERE provider = :provider AND provider_subject = :subject'
        );
        $touch->execute([
            'email' => $identity->email,
            'username' => $identity->username,
            'raw' => json_encode($identity->rawClaims, JSON_THROW_ON_ERROR),
            'last_login' => $now,
            'provider' => $identity->provider,
            'subject' => $identity->subject,
        ]);
    }

    private function findByProviderSubject(string $provider, string $subject): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.* FROM users u
             INNER JOIN linked_identities li ON li.user_id = u.id
             WHERE li.provider = :provider AND li.provider_subject = :subject
             LIMIT 1'
        );
        $stmt->execute(['provider' => $provider, 'subject' => $subject]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapUser($row);
    }

    private function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE primary_email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapUser($row);
    }

    private function findById(string $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapUser($row);
    }

    /** @param array<string, mixed> $row */
    private function mapUser(array $row): User
    {
        return new User(
            id: (string) $row['id'],
            primaryEmail: (string) $row['primary_email'],
            emailVerified: (bool) $row['email_verified'],
            displayName: (string) $row['display_name'],
            avatarUrl: $row['avatar_url'] !== null ? (string) $row['avatar_url'] : null,
            status: (string) $row['status'],
        );
    }
}
