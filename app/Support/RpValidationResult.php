<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

use GrandpaSSOn\Domain\OAuthClient;

/**
 * Outcome of RpRequestValidator::validate(): either the validated RP
 * request params (client_id/redirect_uri/state/PKCE), or a typed error
 * with the HTTP status the caller should respond with.
 */
final class RpValidationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly ?OAuthClient $client = null,
        public readonly ?string $clientId = null,
        public readonly ?string $redirectUri = null,
        public readonly ?string $clientState = null,
        public readonly ?string $returnTo = null,
        public readonly ?string $codeChallenge = null,
        public readonly ?string $codeChallengeMethod = null,
        public readonly ?string $error = null,
        public readonly ?string $message = null,
        public readonly int $status = 200,
    ) {
    }

    public static function success(
        OAuthClient $client,
        string $clientId,
        string $redirectUri,
        string $clientState,
        ?string $returnTo,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
    ): self {
        return new self(
            ok: true,
            client: $client,
            clientId: $clientId,
            redirectUri: $redirectUri,
            clientState: $clientState,
            returnTo: $returnTo,
            codeChallenge: $codeChallenge,
            codeChallengeMethod: $codeChallengeMethod,
        );
    }

    public static function failure(string $error, string $message, int $status): self
    {
        return new self(ok: false, error: $error, message: $message, status: $status);
    }
}
