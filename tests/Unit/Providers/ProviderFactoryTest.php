<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Providers;

use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Providers\ProviderFactory;
use PHPUnit\Framework\TestCase;

final class ProviderFactoryTest extends TestCase
{
    public function testMakesGoogle(): void
    {
        $factory = new ProviderFactory([
            'providers' => [
                'google' => [
                    'client_id' => 'g',
                    'client_secret' => 's',
                    'redirect_uri' => 'http://localhost/callback/google',
                    'scopes' => ['openid', 'email', 'profile'],
                ],
            ],
        ]);

        $this->assertSame('google', $factory->make('google')->getName());
    }

    public function testRejectsMissingCredentials(): void
    {
        $factory = new ProviderFactory([
            'providers' => [
                'github' => [
                    'client_id' => '',
                    'client_secret' => '',
                    'redirect_uri' => 'http://localhost/callback/github',
                ],
            ],
        ]);

        $this->expectException(ProviderException::class);
        $factory->make('github');
    }

    public function testMicrosoftRequiresTenantInConfig(): void
    {
        $factory = new ProviderFactory([
            'providers' => [
                'microsoft' => [
                    'client_id' => 'm',
                    'client_secret' => 's',
                    'redirect_uri' => 'http://localhost/callback/microsoft',
                    'tenant_id' => '',
                ],
            ],
        ]);

        $this->expectException(ProviderException::class);
        $factory->make('microsoft');
    }
}
