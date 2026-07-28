<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;
use PDO;

/**
 * Shared client_id/redirect_uri/state/PKCE validation for anything that
 * mints a broker session on behalf of an RP (OAuth login, email OTP login).
 * Extracted from LoginController::start() so there is one source of truth
 * for this validation rather than copies that can drift.
 */
final class RpRequestValidator
{
    /**
     * @param array<string, mixed> $query Typically $_GET (or the RP params
     *   carried through a hidden form for a later POST step).
     */
    public static function validate(PDO $pdo, array $query): RpValidationResult
    {
        $clientId = (string) ($query['client_id'] ?? '');
        $redirectUri = (string) ($query['redirect_uri'] ?? '');
        $clientState = (string) ($query['state'] ?? '');
        $returnTo = (string) ($query['return_to'] ?? '');
        $rpChallenge = trim((string) ($query['code_challenge'] ?? ''));
        $rpChallengeMethod = strtoupper(trim((string) ($query['code_challenge_method'] ?? 'S256')));

        if ($clientId === '' || $redirectUri === '' || $clientState === '') {
            return RpValidationResult::failure(
                'invalid_request',
                'client_id, redirect_uri, and state are required',
                400,
            );
        }

        $client = (new OAuthClientRepository($pdo))->findByClientId($clientId);
        if ($client === null) {
            return RpValidationResult::failure('invalid_client', 'Unknown client_id', 400);
        }
        if (!$client->enabled) {
            return RpValidationResult::failure('disabled_client', 'OAuth client is disabled', 403);
        }
        if (!$client->allowsRedirectUri($redirectUri)) {
            return RpValidationResult::failure('invalid_redirect_uri', 'redirect_uri does not match registered URIs', 400);
        }

        // S6 / R11: public clients must use PKCE (S256).
        if (!$client->isConfidential()) {
            if ($rpChallenge === '' || $rpChallengeMethod !== 'S256') {
                return RpValidationResult::failure(
                    'invalid_request',
                    'public clients require code_challenge with code_challenge_method=S256',
                    400,
                );
            }
        } elseif ($rpChallenge !== '' && $rpChallengeMethod !== 'S256') {
            return RpValidationResult::failure(
                'invalid_request',
                'Only S256 code_challenge_method is supported',
                400,
            );
        }

        return RpValidationResult::success(
            $client,
            $clientId,
            $redirectUri,
            $clientState,
            $returnTo !== '' ? $returnTo : null,
            $rpChallenge !== '' ? $rpChallenge : null,
            $rpChallenge !== '' ? $rpChallengeMethod : null,
        );
    }
}
