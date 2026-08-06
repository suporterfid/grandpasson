<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Http\Controllers;

use GrandpaSSOn\Http\Controllers\LoginController;
use PHPUnit\Framework\TestCase;

final class LoginControllerChooserTest extends TestCase
{
    protected function setUp(): void
    {
        $_GET = [];
    }

    public function testChooserAtWebRoot(): void
    {
        $config = ['broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost:8080']];

        ob_start();
        (new LoginController())->chooser($config);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<meta name="viewport"', $html);
        $this->assertStringContainsString('<h1>GrandpaSSOn</h1>', $html);
        $this->assertStringContainsString('href="/login/google"', $html);
        $this->assertStringContainsString('href="/login/microsoft"', $html);
        $this->assertStringContainsString('href="/login/github"', $html);
        $this->assertStringContainsString('Continue with Google', $html);
        $this->assertStringContainsString('Continue with Microsoft', $html);
        $this->assertStringContainsString('Continue with GitHub', $html);
        $this->assertStringContainsString('btn btn--secondary', $html);
        $this->assertStringContainsString('href="/login/email"', $html);
        $this->assertStringContainsString('Or continue with email', $html);
    }

    public function testChooserForwardsRpParamsOnEmailLink(): void
    {
        $config = ['broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost:8080']];
        $_GET = ['client_id' => 'cid', 'redirect_uri' => 'https://app.example/cb', 'state' => 's'];

        ob_start();
        (new LoginController())->chooser($config);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(
            'href="/login/email?client_id=cid&amp;redirect_uri=https%3A%2F%2Fapp.example%2Fcb&amp;state=s"',
            $html
        );
    }

    public function testChooserIncludesSignupLink(): void
    {
        $config = ['broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost:8080']];
        $_GET = ['client_id' => 'cid', 'redirect_uri' => 'https://app.example/cb', 'state' => 's'];

        ob_start();
        (new LoginController())->chooser($config);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString(
            'href="/signup?client_id=cid&amp;redirect_uri=https%3A%2F%2Fapp.example%2Fcb&amp;state=s"',
            $html
        );
        $this->assertStringContainsString('New here? Request access', $html);
    }

    public function testChooserBuildsSubpathPrefixedHrefs(): void
    {
        $config = ['broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'https://host/sso']];

        ob_start();
        (new LoginController())->chooser($config);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('href="/sso/login/google"', $html);
        $this->assertStringContainsString('href="/sso/login/microsoft"', $html);
        $this->assertStringContainsString('href="/sso/login/github"', $html);
    }

    public function testChooserEscapesBrokerName(): void
    {
        $config = ['broker' => ['name' => '<script>alert(1)</script>', 'base_url' => 'http://localhost:8080']];

        ob_start();
        (new LoginController())->chooser($config);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testChooserDefaultsBrokerNameWhenMissing(): void
    {
        $config = ['broker' => ['base_url' => 'http://localhost:8080']];

        ob_start();
        (new LoginController())->chooser($config);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<h1>GrandpaSSOn</h1>', $html);
    }
}
