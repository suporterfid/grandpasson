<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

use GrandpaSSOn\Domain\Uuid;
use PDO;

/**
 * Email one-time-code login (R17). Mirrors AuthCodeService's shape: the raw
 * code is generated and returned once, only its hash is ever persisted.
 */
final class EmailOtpService
{
    /**
     * Cap on concurrent pending codes for one recipient, independent of
     * per-IP RateLimitGate throttling — closes the email-bombing gap where
     * many different IPs target a single victim's inbox.
     */
    private const MAX_PENDING_PER_EMAIL = 3;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array{
     *   client_id: string,
     *   redirect_uri: string,
     *   client_state: string,
     *   return_to: ?string,
     *   code_challenge: ?string,
     *   code_challenge_method: ?string,
     * } $rpParams
     * @return array{id: string, code: ?string} code is null when the
     *   per-email pending cap was hit — the caller must still respond as if
     *   a code was sent, to avoid revealing the throttle to an enumerator.
     */
    public function start(string $email, array $rpParams, int $ttlSeconds, int $maxAttempts, int $codeLength): array
    {
        $email = strtolower(trim($email));
        $id = Uuid::v4();

        $pending = $this->pdo->prepare(
            'SELECT COUNT(*) FROM email_otp_codes
             WHERE email = :email AND consumed = 0 AND expires_at > UTC_TIMESTAMP()'
        );
        $pending->execute(['email' => $email]);
        if ((int) $pending->fetchColumn() >= self::MAX_PENDING_PER_EMAIL) {
            return ['id' => $id, 'code' => null];
        }

        $ceiling = (int) (10 ** $codeLength);
        $code = str_pad((string) random_int(0, $ceiling - 1), $codeLength, '0', STR_PAD_LEFT);
        $hash = hash('sha256', $code);

        $stmt = $this->pdo->prepare(
            'INSERT INTO email_otp_codes
             (id, email, code_hash, client_id, redirect_uri, client_state, return_to,
              code_challenge, code_challenge_method, attempts, max_attempts, expires_at, consumed, created_at)
             VALUES
             (:id, :email, :hash, :client_id, :redirect_uri, :client_state, :return_to,
              :challenge, :method, 0, :max_attempts, DATE_ADD(UTC_TIMESTAMP(), INTERVAL :ttl SECOND), 0, UTC_TIMESTAMP())'
        );
        $stmt->execute([
            'id' => $id,
            'email' => $email,
            'hash' => $hash,
            'client_id' => $rpParams['client_id'],
            'redirect_uri' => $rpParams['redirect_uri'],
            'client_state' => $rpParams['client_state'],
            'return_to' => $rpParams['return_to'] ?? null,
            'challenge' => $rpParams['code_challenge'] ?? null,
            'method' => $rpParams['code_challenge_method'] ?? null,
            'max_attempts' => $maxAttempts,
            'ttl' => $ttlSeconds,
        ]);

        return ['id' => $id, 'code' => $code];
    }

    public function verify(string $id, string $submittedCode): EmailOtpVerifyResult
    {
        try {
            $this->pdo->beginTransaction();

            $select = $this->pdo->prepare(
                'SELECT * FROM email_otp_codes WHERE id = :id AND consumed = 0 FOR UPDATE'
            );
            $select->execute(['id' => $id]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                $this->pdo->rollBack();

                return EmailOtpVerifyResult::notFound();
            }

            // DB-side clock, not PHP's, is authoritative for expiry.
            $now = (string) $this->pdo->query('SELECT UTC_TIMESTAMP()')->fetchColumn();
            if ((string) $row['expires_at'] <= $now) {
                $this->pdo->rollBack();

                return EmailOtpVerifyResult::expired();
            }

            $storedHash = (string) $row['code_hash'];
            $submittedHash = hash('sha256', $submittedCode);

            if (!hash_equals($storedHash, $submittedHash)) {
                $attempts = (int) $row['attempts'] + 1;
                $maxAttempts = (int) $row['max_attempts'];

                if ($attempts >= $maxAttempts) {
                    $lock = $this->pdo->prepare(
                        'UPDATE email_otp_codes SET attempts = :attempts, consumed = 1 WHERE id = :id'
                    );
                    $lock->execute(['attempts' => $attempts, 'id' => $id]);
                    $this->pdo->commit();

                    return EmailOtpVerifyResult::locked();
                }

                $update = $this->pdo->prepare(
                    'UPDATE email_otp_codes SET attempts = :attempts WHERE id = :id'
                );
                $update->execute(['attempts' => $attempts, 'id' => $id]);
                $this->pdo->commit();

                return EmailOtpVerifyResult::wrongCode($maxAttempts - $attempts);
            }

            $update = $this->pdo->prepare(
                'UPDATE email_otp_codes SET consumed = 1 WHERE id = :id AND consumed = 0'
            );
            $update->execute(['id' => $id]);
            if ($update->rowCount() !== 1) {
                $this->pdo->rollBack();

                return EmailOtpVerifyResult::notFound();
            }
            $this->pdo->commit();

            return EmailOtpVerifyResult::ok(
                email: (string) $row['email'],
                clientId: (string) $row['client_id'],
                redirectUri: (string) $row['redirect_uri'],
                clientState: (string) $row['client_state'],
                returnTo: $row['return_to'] !== null ? (string) $row['return_to'] : null,
                codeChallenge: $row['code_challenge'] !== null ? (string) $row['code_challenge'] : null,
                codeChallengeMethod: $row['code_challenge_method'] !== null ? (string) $row['code_challenge_method'] : null,
            );
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
