<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

/**
 * Thrown by UserProvisioner::resolve() when the matched user is awaiting
 * admin approval (self-enrollment).
 */
final class AccountPendingException extends ProviderException
{
}
