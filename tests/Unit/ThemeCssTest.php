<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ThemeCssTest extends TestCase
{
    private const PATH = __DIR__ . '/../../public_html/assets/theme.css';

    /** @var list<string> */
    private const REQUIRED_COLOR_TOKENS = [
        '--color-bg-canvas', '--color-bg-surface', '--color-bg-elevated', '--color-bg-hover', '--color-bg-selected',
        '--color-text-primary', '--color-text-secondary', '--color-text-disabled', '--color-text-inverse', '--color-text-link',
        '--color-border-default', '--color-border-strong',
        '--color-action-primary', '--color-action-primary-hover', '--color-action-primary-active', '--color-action-primary-content', '--color-action-primary-subtle',
        '--color-focus-ring',
        '--color-success-fg', '--color-success-bg', '--color-success-border',
        '--color-warning-fg', '--color-warning-bg', '--color-warning-border',
        '--color-danger-fg', '--color-danger-bg', '--color-danger-border',
        '--color-info-fg', '--color-info-bg', '--color-info-border',
    ];

    public function testThemeCssExists(): void
    {
        $this->assertFileExists(self::PATH);
    }

    public function testEachThemeDefinesTheCompleteCanonicalColorContract(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $tokens = $this->parseThemeColorTokens($theme);
            $this->assertCount(30, $tokens, "{$theme} must define exactly the 30 canonical color tokens");
            $this->assertSame(self::REQUIRED_COLOR_TOKENS, array_keys($tokens), "{$theme} is missing or has changed canonical color tokens");
        }
    }

    public function testLegacyColorAliasesResolveToCanonicalTokens(): void
    {
        $css = (string) file_get_contents(self::PATH);
        $aliases = [
            '--color-canvas' => '--color-bg-canvas',
            '--color-surface' => '--color-bg-surface',
            '--color-surface-emphasis' => '--color-bg-elevated',
            '--color-text' => '--color-text-primary',
            '--color-text-muted' => '--color-text-secondary',
            '--color-action' => '--color-action-primary',
            '--color-action-hover' => '--color-action-primary-hover',
            '--color-focus' => '--color-focus-ring',
        ];

        foreach ($aliases as $alias => $canonical) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($alias, '/') . '\\s*:\\s*var\\(' . preg_quote($canonical, '/') . '\\)\\s*;/',
                $css,
                "{$alias} must remain a canonical-token alias"
            );
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

    /** @return array<string, string> */
    private function parseThemeColorTokens(string $theme): array
    {
        $css = (string) file_get_contents(self::PATH);
        $selector = $theme === 'light'
            ? ':root,\\s*\\[data-theme=[\'\"]light[\'\"]\\]'
            : '\\[data-theme=[\'\"]dark[\'\"]\\]';

        $this->assertMatchesRegularExpression('/' . $selector . '\\s*\\{/', $css, "Missing {$theme} theme token block");
        preg_match('/' . $selector . '\\s*\\{(?<block>.*?)\\n\\}/s', $css, $matches);
        $this->assertArrayHasKey('block', $matches, "Cannot parse {$theme} theme token block");

        preg_match_all('/(?<token>--color-[a-z-]+)\\s*:\\s*(?<value>#[0-9a-fA-F]{6})\\s*;/', $matches['block'], $declarations, PREG_SET_ORDER);
        $tokens = [];
        foreach ($declarations as $declaration) {
            $tokens[$declaration['token']] = strtolower($declaration['value']);
        }

        return $tokens;
    }
}
