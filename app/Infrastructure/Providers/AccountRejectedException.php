<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

/**
 * Thrown by UserProvisioner::resolve() when the matched user's signup was
 * rejected by an admin.
 */
final class AccountRejectedException extends ProviderException
{
}
