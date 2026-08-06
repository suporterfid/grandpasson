<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

/**
 * Thrown by UserProvisioner::resolve() when no user matches the identity.
 * CallbackController catches this to redirect into the signup-completion
 * screen instead of treating it as a generic login failure.
 */
final class AccountNotFoundException extends ProviderException
{
}
