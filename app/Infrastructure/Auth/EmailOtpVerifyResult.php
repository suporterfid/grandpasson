<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Auth;

/**
 * Typed outcome of EmailOtpService::verify(), so callers branch on status
 * instead of juggling nullable strings.
 */
final class EmailOtpVerifyResult
{
    public const OK = 'ok';
    public const WRONG_CODE = 'wrong_code';
    public const EXPIRED = 'expired';
    public const LOCKED = 'locked';
    public const NOT_FOUND = 'not_found';

    private function __construct(
        public readonly string $status,
        public readonly ?string $email = null,
        public readonly ?string $clientId = null,
        public readonly ?string $redirectUri = null,
        public readonly ?string $clientState = null,
        public readonly ?string $returnTo = null,
        public readonly ?string $codeChallenge = null,
        public readonly ?string $codeChallengeMethod = null,
        public readonly ?int $attemptsRemaining = null,
    ) {
    }

    public static function ok(
        string $email,
        string $clientId,
        string $redirectUri,
        string $clientState,
        ?string $returnTo,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
    ): self {
        return new self(
            status: self::OK,
            email: $email,
            clientId: $clientId,
            redirectUri: $redirectUri,
            clientState: $clientState,
            returnTo: $returnTo,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
        );
    }

    public static function wrongCode(int $attemptsRemaining): self
    {
        return new self(status: self::WRONG_CODE, attemptsRemaining: $attemptsRemaining);
    }

    public static function expired(): self
    {
        return new self(status: self::EXPIRED);
    }

    public static function locked(): self
    {
        return new self(status: self::LOCKED);
    }

    public static function notFound(): self
    {
        return new self(status: self::NOT_FOUND);
    }

    public function isOk(): bool
    {
        return $this->status === self::OK;
    }
}
