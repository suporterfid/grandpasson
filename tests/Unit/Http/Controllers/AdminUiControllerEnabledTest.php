<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Http\Controllers;

use GrandpaSSOn\Http\Controllers\AdminUiController;
use GrandpaSSOn\Support\RateLimitGate;
use PHPUnit\Framework\TestCase;

final class AdminUiControllerEnabledTest extends TestCase
{
    protected function setUp(): void
    {
        RateLimitGate::reset();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        $_SESSION = [];
    }

    public function testRendersThemedShellAndExternalAssetsAtWebRoot(): void
    {
        $config = ['broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost:8080'], 'admin_api_token' => 'test-token'];

        http_response_code(200);
        ob_start();
        (new AdminUiController())->index($config);
        $html = (string) ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('href="/assets/theme.css"', $html);
        $this->assertStringContainsString('<script src="/assets/admin.js" defer></script>', $html);
        $this->assertStringContainsString('data-api-url="/admin/api"', $html);
        $this->assertStringContainsString('<button class="btn btn--primary" type="submit">Run</button>', $html);
        $this->assertStringNotContainsString('<style>', $html);
        $this->assertStringNotContainsString('IBM Plex', $html);
        $this->assertStringNotContainsString('color-scheme: light', $html);
    }

    public function testBuildsSubpathPrefixedAssetAndApiUrls(): void
    {
        $config = ['broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'https://host/sso'], 'admin_api_token' => 'test-token'];

        ob_start();
        (new AdminUiController())->index($config);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('href="/sso/assets/theme.css"', $html);
        $this->assertStringContainsString('<script src="/sso/assets/admin.js" defer></script>', $html);
        $this->assertStringContainsString('data-api-url="/sso/admin/api"', $html);
    }

    public function testEscapesBrokerNameInHeading(): void
    {
        $config = ['broker' => ['name' => '<script>alert(1)</script>', 'base_url' => 'http://localhost:8080'], 'admin_api_token' => 'test-token'];

        ob_start();
        (new AdminUiController())->index($config);
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
