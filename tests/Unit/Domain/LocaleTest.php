<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Domain;

use GrandpaSSOn\Domain\Locale;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    public function testDefaultIsPtBr(): void
    {
        $this->assertSame('pt-BR', Locale::DEFAULT);
    }

    public function testSupportedContainsExactlyPtBrAndEn(): void
    {
        $this->assertSame(['pt-BR', 'en'], Locale::SUPPORTED);
    }

    public function testIsSupportedAcceptsKnownLocales(): void
    {
        $this->assertTrue(Locale::isSupported('pt-BR'));
        $this->assertTrue(Locale::isSupported('en'));
    }

    public function testIsSupportedRejectsUnknownLocale(): void
    {
        $this->assertFalse(Locale::isSupported('es'));
        $this->assertFalse(Locale::isSupported(''));
        $this->assertFalse(Locale::isSupported('PT-BR'));
    }
}
