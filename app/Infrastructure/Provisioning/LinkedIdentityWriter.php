<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Provisioning;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use PDO;

/**
 * Shared linked_identities INSERT — used by UserProvisioner (linking a new
 * provider to an already-active account) and SignupService (first-time
 * signup), so the column list only lives in one place.
 */
final class LinkedIdentityWriter
{
    public static function insert(PDO $pdo, string $userId, NormalizedIdentity $identity): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
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
}
