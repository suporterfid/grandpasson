<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeCssTest extends TestCase
{
    private const PATH = __DIR__ . '/../../public_html/assets/theme.css';

    public function testThemeCssExists(): void
    {
        $this->assertFileExists(self::PATH);
    }

    public function testDefinesAllRequiredSemanticTokens(): void
    {
        $css = (string) file_get_contents(self::PATH);
        $required = [
            '--color-canvas',
            '--color-surface',
            '--color-surface-emphasis',
            '--color-text',
            '--color-text-muted',
            '--color-text-inverse',
            '--color-border',
            '--color-border-strong',
            '--color-action',
            '--color-action-hover',
            '--color-focus',
        ];
        foreach ($required as $token) {
            $this->assertStringContainsString($token . ':', $css, "Missing token {$token}");
        }
    }

    public function testHasNoImportOrExternalUrl(): void
    {
        $css = (string) file_get_contents(self::PATH);
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('http://', $css);
        $this->assertStringNotContainsString('https://', $css);
    }

    public function testHasReducedMotionBlock(): void
    {
        $css = (string) file_get_contents(self::PATH);
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
    }
}
