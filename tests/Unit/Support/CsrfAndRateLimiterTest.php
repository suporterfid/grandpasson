<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Support;

use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimiter;
use PHPUnit\Framework\TestCase;

final class CsrfAndRateLimiterTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $_SESSION = [];
        $this->dir = sys_get_temp_dir() . '/gp-rate-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($this->dir);
        }
    }

    public function testCsrfRoundTrip(): void
    {
        $token = Csrf::token();
        $this->assertNotSame('', $token);
        $this->assertTrue(Csrf::validate($token));
        $this->assertFalse(Csrf::validate('nope'));
        $this->assertFalse(Csrf::validate(''));
    }

    public function testRateLimiterAllowsThenBlocks(): void
    {
        $limiter = new RateLimiter($this->dir, 3, 60);
        $now = 1_700_000_000;

        $this->assertTrue($limiter->attempt('ip|login', $now));
        $this->assertTrue($limiter->attempt('ip|login', $now + 1));
        $this->assertTrue($limiter->attempt('ip|login', $now + 2));
        $this->assertFalse($limiter->attempt('ip|login', $now + 3));

        // Different key is independent.
        $this->assertTrue($limiter->attempt('other|login', $now + 3));

        // After window slides, allowed again.
        $this->assertTrue($limiter->attempt('ip|login', $now + 61));
    }
}
