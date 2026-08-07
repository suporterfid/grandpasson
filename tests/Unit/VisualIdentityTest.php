<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VisualIdentityTest extends TestCase
{
    private const THEME_CSS_PATH = __DIR__ . '/../../public_html/assets/theme.css';

    public function testControllersContainNoRawColorLiterals(): void
    {
        $dir = dirname(__DIR__, 2) . '/app/Http/Controllers';
        $offenders = [];

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/#[0-9a-fA-F]{3,8}\\b|rgb\\(|hsl\\(/', $contents) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame([], $offenders, 'Controllers must consume semantic tokens, not raw palette values: ' . implode(', ', $offenders));
    }

    public function testCanonicalContrastPairsMeetTheirDocumentedFloorsInBothThemes(): void
    {
        foreach (['light', 'dark'] as $theme) {
            $tokens = $this->parseThemeColorTokens($theme);

            foreach ([
                ['--color-text-primary', '--color-bg-canvas', 4.5, 'primary text on canvas'],
                ['--color-text-secondary', '--color-bg-canvas', 4.5, 'secondary text on canvas'],
                ['--color-text-primary', '--color-bg-surface', 4.5, 'primary text on surface'],
                ['--color-text-secondary', '--color-bg-surface', 4.5, 'secondary text on surface'],
                ['--color-text-primary', '--color-bg-elevated', 4.5, 'primary text on elevated surface'],
                ['--color-text-link', '--color-bg-canvas', 4.5, 'link text on canvas'],
                ['--color-action-primary-content', '--color-action-primary', 4.5, 'primary action content'],
                ['--color-success-fg', '--color-success-bg', 4.5, 'success status'],
                ['--color-warning-fg', '--color-warning-bg', 4.5, 'warning status'],
                ['--color-danger-fg', '--color-danger-bg', 4.5, 'danger status'],
                ['--color-info-fg', '--color-info-bg', 4.5, 'info status'],
                ['--color-focus-ring', '--color-bg-canvas', 3.0, 'focus ring'],
                ['--color-border-strong', '--color-bg-canvas', 3.0, 'control boundary'],
            ] as [$foreground, $background, $minimum, $label]) {
                $this->assertGreaterThanOrEqual(
                    $minimum,
                    $this->contrast($tokens[$foreground], $tokens[$background]),
                    "{$theme}: {$label}"
                );
            }
        }
    }

    public function testThemeCssHasNoImportOrExternalUrl(): void
    {
        $css = $this->themeCss();
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('http://', $css);
        $this->assertStringNotContainsString('https://', $css);
    }

    public function testThemeCssHasReducedMotionBlock(): void
    {
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $this->themeCss());
    }

    public function testThemeCssBreaksLongUnbrokenStrings(): void
    {
        $this->assertStringContainsString('overflow-wrap: anywhere', $this->themeCss());
    }

    public function testRawHexInThemeCssOnlyAppearsInTokenDeclarations(): void
    {
        $lines = explode("\n", $this->themeCss());
        $offenders = [];

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/#[0-9a-fA-F]{3,8}\\b/', $line) !== 1) {
                continue;
            }
            if (preg_match('/^\\s*--color-[a-z0-9-]+\\s*:\\s*#[0-9a-fA-F]{6}\\s*;\\s*$/', $line) === 1) {
                continue;
            }
            $offenders[] = 'line ' . ($lineNumber + 1) . ': ' . trim($line);
        }

        $this->assertSame([], $offenders, "Raw hex outside token declarations:\n" . implode("\n", $offenders));
    }

    private function themeCss(): string
    {
        return (string) file_get_contents(self::THEME_CSS_PATH);
    }

    /** @return array<string, string> */
    private function parseThemeColorTokens(string $theme): array
    {
        $css = $this->themeCss();
        $selector = $theme === 'light'
            ? ':root,\\s*\\[data-theme=[\'\"]light[\'\"]\\]'
            : '\\[data-theme=[\'\"]dark[\'\"]\\]';

        preg_match('/' . $selector . '\\s*\\{(?<block>.*?)\\n\\}/s', $css, $matches);
        $this->assertArrayHasKey('block', $matches, "Cannot parse {$theme} theme token block");

        preg_match_all('/(?<token>--color-[a-z-]+)\\s*:\\s*(?<value>#[0-9a-fA-F]{6})\\s*;/', $matches['block'], $declarations, PREG_SET_ORDER);
        $tokens = [];
        foreach ($declarations as $declaration) {
            $tokens[$declaration['token']] = strtolower($declaration['value']);
        }

        return $tokens;
    }

    private function contrast(string $hexA, string $hexB): float
    {
        $lumA = $this->relativeLuminance($hexA);
        $lumB = $this->relativeLuminance($hexB);

        return (max($lumA, $lumB) + 0.05) / (min($lumA, $lumB) + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $channels = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $linear = array_map(static function (int $channel): float {
            $value = $channel / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $linear[0] + 0.7152 * $linear[1] + 0.0722 * $linear[2];
    }
}
