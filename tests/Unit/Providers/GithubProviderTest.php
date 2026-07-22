<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Providers;

use GrandpaSSOn\Infrastructure\Providers\GithubProvider;
use GrandpaSSOn\Infrastructure\Providers\Pkce;
use League\OAuth2\Client\Token\AccessToken;
use PHPUnit\Framework\TestCase;

final class GithubProviderTest extends TestCase
{
    public function testPickVerifiedEmailPrefersPrimary(): void
    {
        [$email, $verified] = GithubProvider::pickVerifiedEmail(
            ['email' => 'noreply@users.noreply.github.com'],
            [
                ['email' => 'other@example.com', 'primary' => false, 'verified' => true],
                ['email' => 'primary@example.com', 'primary' => true, 'verified' => true],
            ]
        );

        $this->assertSame('primary@example.com', $email);
        $this->assertTrue($verified);
    }

    public function testPickVerifiedEmailIgnoresUnverifiedProfileEmail(): void
    {
        [$email, $verified] = GithubProvider::pickVerifiedEmail(
            ['email' => 'maybe@example.com'],
            [
                ['email' => 'maybe@example.com', 'primary' => true, 'verified' => false],
            ]
        );

        $this->assertNull($email);
        $this->assertFalse($verified);
    }

    public function testAuthorizationUrlIncludesPkce(): void
    {
        $provider = new GithubProvider([
            'client_id' => 'gh',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://localhost:8080/callback/github',
        ]);
        $pkce = Pkce::generate();
        $url = $provider->getAuthorizationUrl('state-gh', null, $pkce);

        $this->assertStringContainsString('github.com/login/oauth/authorize', $url);
        $this->assertStringContainsString('state=state-gh', $url);
        $this->assertStringContainsString('code_challenge=' . $pkce['code_challenge'], $url);
    }

    public function testHandleCallbackUsesEmailsApi(): void
    {
        $provider = new GithubProvider(
            [
                'client_id' => 'gh',
                'client_secret' => 'secret',
                'redirect_uri' => 'http://localhost:8080/callback/github',
            ],
            static fn (): AccessToken => new AccessToken(['access_token' => 't']),
            static fn (): array => [
                'profile' => [
                    'id' => 42,
                    'login' => 'octocat',
                    'name' => 'The Octocat',
                    'avatar_url' => 'https://github.com/images/error/octocat_happy.gif',
                    'email' => null,
                ],
                'emails' => [
                    ['email' => 'octocat@example.com', 'primary' => true, 'verified' => true],
                ],
            ],
        );

        $identity = $provider->handleCallback(['code' => 'abc']);
        $this->assertSame('github', $identity->provider);
        $this->assertSame('42', $identity->subject);
        $this->assertSame('octocat', $identity->username);
        $this->assertSame('octocat@example.com', $identity->email);
        $this->assertTrue($identity->emailVerified);
    }
}
