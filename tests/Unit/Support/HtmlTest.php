<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Support;

use GrandpaSSOn\Support\Html;
use PHPUnit\Framework\TestCase;

final class HtmlTest extends TestCase
{
    public function testBasePathAtWebRoot(): void
    {
        $config = ['broker' => ['base_url' => 'http://localhost:8080']];
        $this->assertSame('', Html::basePath($config));
    }

    public function testBasePathWithBareRootPath(): void
    {
        $config = ['broker' => ['base_url' => 'https://host/']];
        $this->assertSame('', Html::basePath($config));
    }

    public function testBasePathWithSubpath(): void
    {
        $config = ['broker' => ['base_url' => 'https://host/sso']];
        $this->assertSame('/sso', Html::basePath($config));
    }

    public function testBasePathWithTrailingSlashSubpath(): void
    {
        $config = ['broker' => ['base_url' => 'https://host/sso/']];
        $this->assertSame('/sso', Html::basePath($config));
    }

    public function testBasePathWithEmptyBrokerBaseUrl(): void
    {
        $config = ['broker' => ['base_url' => '']];
        $this->assertSame('', Html::basePath($config));
    }

    public function testAssetAtWebRoot(): void
    {
        $config = ['broker' => ['base_url' => 'http://localhost:8080']];
        $this->assertSame('/assets/theme.css', Html::asset($config, 'theme.css'));
    }

    public function testAssetUnderSubpath(): void
    {
        $config = ['broker' => ['base_url' => 'https://host/sso']];
        $this->assertSame('/sso/assets/theme.css', Html::asset($config, 'theme.css'));
    }

    public function testAssetStripsLeadingSlashOnRelativePath(): void
    {
        $config = ['broker' => ['base_url' => 'https://host/sso']];
        $this->assertSame('/sso/assets/fonts/open-sans-400.woff2', Html::asset($config, '/fonts/open-sans-400.woff2'));
    }

    public function testPageStartEmitsLangViewportCharsetTitleAndThemeLink(): void
    {
        $config = ['broker' => ['base_url' => 'https://host/sso']];
        $html = Html::pageStart($config, 'GrandpaSSOn Login');

        $this->assertStringContainsString('<html lang="en">', $html);
        $this->assertStringContainsString('<meta name="viewport" content="width=device-width, initial-scale=1">', $html);
        $this->assertStringContainsString('<meta charset="utf-8">', $html);
        $this->assertStringContainsString('<title>GrandpaSSOn Login</title>', $html);
        $this->assertStringContainsString('href="/sso/assets/theme.css"', $html);
        $this->assertStringContainsString('<body>', $html);
    }

    public function testPageStartEscapesTitleAndAppliesBodyClass(): void
    {
        $config = ['broker' => ['base_url' => 'http://localhost:8080']];
        $html = Html::pageStart($config, '<script>alert(1)</script>', 'admin-page');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<body class="admin-page">', $html);
    }

    public function testPageEnd(): void
    {
        $this->assertSame('</body></html>', Html::pageEnd());
    }

    public function testEscapesQuotesAndInvalidUtf8(): void
    {
        $this->assertSame('&quot;quoted&quot;', Html::e('"quoted"'));
        $this->assertSame('&#039;single&#039;', Html::e("'single'"));
        // ENT_SUBSTITUTE turns an invalid byte sequence into U+FFFD rather than an empty string.
        $this->assertStringContainsString("\u{FFFD}", Html::e("bad\x80utf8"));
    }
}
