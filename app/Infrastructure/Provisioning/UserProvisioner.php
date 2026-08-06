<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Provisioning;

use GrandpaSSOn\Domain\User;
use GrandpaSSOn\Infrastructure\Providers\AccountNotFoundException;
use GrandpaSSOn\Infrastructure\Providers\AccountPendingException;
use GrandpaSSOn\Infrastructure\Providers\AccountRejectedException;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use PDO;

/**
 * Resolves an already-existing user for a normalized identity (login only —
 * never creates accounts; see SignupService for that). Distinguishes
 * not-found / pending / rejected / disabled so callers can route each case
 * to the right screen.
 */
final class UserProvisioner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @throws AccountNotFoundException No user matches this identity.
     * @throws AccountPendingException Matched user is awaiting approval.
     * @throws AccountRejectedException Matched user's signup was rejected.
     * @throws ProviderException Matched user is disabled, or email is unverified.
     */
    public function resolve(NormalizedIdentity $identity): User
    {
        $existing = $this->findByProviderSubject($identity->provider, $identity->subject);
        if ($existing !== null) {
            $this->assertUsable($existing);
            $this->syncProfileAndTouch($existing, $identity);

            return $this->findById($existing->id) ?? $existing;
        }

        if ($identity->email === null || $identity->email === '' || !$identity->emailVerified) {
            throw new ProviderException('Verified email is required to provision or link an account');
        }

        $email = strtolower($identity->email);
        $byEmail = $this->findByEmail($email);
        if ($byEmail !== null) {
            $this->assertUsable($byEmail);
            LinkedIdentityWriter::insert($this->pdo, $byEmail->id, $identity);
            $this->syncProfileAndTouch($byEmail, $identity);

            return $this->findById($byEmail->id) ?? $byEmail;
        }

        throw new AccountNotFoundException('No account found for this identity; sign up first.');
    }

    private function assertUsable(User $user): void
    {
        if ($user->status === 'pending') {
            throw new AccountPendingException('Your account is awaiting admin approval');
        }
        if ($user->status === 'rejected') {
            throw new AccountRejectedException('Your signup was not approved');
        }
        if (!$user->isActive()) {
            throw new ProviderException('User account is disabled');
        }
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
