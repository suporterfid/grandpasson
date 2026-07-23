<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Support;

use GrandpaSSOn\Support\CronHttpGate;
use PHPUnit\Framework\TestCase;

final class CronHttpGateTest extends TestCase
{
    public function testUnconfiguredTokenAlwaysDenies(): void
    {
        $this->assertFalse(CronHttpGate::authorized('', null));
        $this->assertFalse(CronHttpGate::authorized('', 'anything'));
    }

    public function testMatchingTokenAuthorizes(): void
    {
        $this->assertTrue(CronHttpGate::authorized('secret-token', 'secret-token'));
    }

    public function testMismatchedOrMissingTokenDenies(): void
    {
        $this->assertFalse(CronHttpGate::authorized('secret-token', 'wrong-token'));
        $this->assertFalse(CronHttpGate::authorized('secret-token', null));
        $this->assertFalse(CronHttpGate::authorized('secret-token', ''));
    }
}
