<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Cleanup;

use PDO;

final class EmailOtpCleanup
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Deletes expired or already-consumed email OTP codes.
     *
     * @return int Number of deleted rows
     */
    public function run(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM email_otp_codes WHERE consumed = 1 OR expires_at < UTC_TIMESTAMP()'
        );
        $stmt->execute();

        return $stmt->rowCount();
    }
}
