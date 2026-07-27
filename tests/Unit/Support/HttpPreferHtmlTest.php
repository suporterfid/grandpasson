<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Support;

use GrandpaSSOn\Support\Http;
use PHPUnit\Framework\TestCase;

final class HttpPreferHtmlTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['HTTP_ACCEPT']);
    }

    public function testPrefersHtmlWhenAcceptIncludesHtml(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';
        $this->assertTrue(Http::prefersHtml());
    }

    public function testJsonOnlyDoesNotPreferHtml(): void
    {
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $this->assertFalse(Http::prefersHtml());
    }

    public function testEmptyAcceptDoesNotPreferHtml(): void
    {
        unset($_SERVER['HTTP_ACCEPT']);
        $this->assertFalse(Http::prefersHtml());
    }
}
