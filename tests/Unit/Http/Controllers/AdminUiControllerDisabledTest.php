<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Http\Controllers;

use GrandpaSSOn\Http\Controllers\AdminUiController;
use GrandpaSSOn\Support\RateLimitGate;
use PHPUnit\Framework\TestCase;

final class AdminUiControllerDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        RateLimitGate::reset();
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
    }

    public function testDisabledPageIsCompleteDocumentWithHeading(): void
    {
        $config = ['broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost:8080'], 'admin_api_token' => ''];

        http_response_code(200);
        ob_start();
        (new AdminUiController())->index($config);
        $html = (string) ob_get_clean();

        $this->assertSame(403, http_response_code());
        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<meta name="viewport"', $html);
        $this->assertStringContainsString('<h1>Admin disabled</h1>', $html);
        $this->assertStringContainsString('<code>ADMIN_API_TOKEN</code>', $html);
        $this->assertStringContainsString('</html>', $html);
    }
}
