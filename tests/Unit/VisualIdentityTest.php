<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * VI11 (#98) regression gates: token discipline and the contrast matrix
 * from docs/visual-identity.md, encoded so a future palette or controller
 * change can't silently regress them.
 */
final class VisualIdentityTest extends TestCase
{
    private const THEME_CSS_PATH = __DIR__ . '/../../public_html/assets/theme.css';

    private const REQUIRED_TOKENS = [
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

    public function testControllersContainNoRawColorLiterals(): void
    {
        $dir = dirname(__DIR__, 2) . '/app/Http/Controllers';
        $offenders = [];

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $contents = (string) file_get_contents($file);
            if (preg_match('/#[0-9a-fA-F]{3,8}\b|rgb\(|hsl\(/', $contents) === 1) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Controllers must consume semantic tokens (spec §2), not raw palette values: ' . implode(', ', $offenders)
        );
    }

    public function testThemeCssDefinesEverySemanticToken(): void
    {
        $css = $this->themeCss();
        foreach (self::REQUIRED_TOKENS as $token) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($token, '/') . '\s*:/',
                $css,
                "theme.css must define {$token}"
            );
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
        $css = $this->themeCss();
        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
    }

    /**
     * Regression test for a real bug found while running the VI11 manual
     * accessibility checklist (spec §10 "test with longer translated
     * strings"): without overflow-wrap, a BROKER_NAME with no spaces (a
     * single unbroken token) overflowed the viewport at 320px instead of
     * wrapping. Verified with a headless-browser snapshot before/after;
     * this test guards the CSS property that fixed it.
     */
    public function testThemeCssBreaksLongUnbrokenStrings(): void
    {
        $css = $this->themeCss();
        $this->assertStringContainsString('overflow-wrap: break-word', $css);
    }

    public function testRawHexInThemeCssOnlyAppearsInTokenDeclarations(): void
    {
        $css = $this->themeCss();
        $lines = explode("\n", $css);
        $offenders = [];

        foreach ($lines as $lineNumber => $line) {
            if (preg_match('/#[0-9a-fA-F]{3,8}\b/', $line) !== 1) {
                continue;
            }
            // A token declaration line looks like: "  --color-x: #rrggbb;"
            if (preg_match('/^\s*--[a-z0-9-]+\s*:\s*#[0-9a-fA-F]{3,8}\s*;\s*$/', $line) === 1) {
                continue;
            }
            $offenders[] = 'line ' . ($lineNumber + 1) . ': ' . trim($line);
        }

        $this->assertSame(
            [],
            $offenders,
            "Raw hex outside token declarations:\n" . implode("\n", $offenders)
        );
    }

    /**
     * WCAG 2.x contrast ratios, computed from the hex values theme.css
     * actually ships (not duplicated constants) so a palette edit that
     * breaks AA fails this test instead of silently shipping.
     */
    public function testContrastMatrix(): void
    {
        $tokens = $this->parseColorTokens();

        $this->assertGreaterThanOrEqual(4.5, $this->contrast($tokens['--color-text'], $tokens['--color-canvas']), 'text on canvas');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($tokens['--color-text-muted'], $tokens['--color-canvas']), 'text-muted on canvas');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($tokens['--color-text'], $tokens['--color-surface']), 'text on surface');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($tokens['--color-text-muted'], $tokens['--color-surface']), 'text-muted on surface');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($tokens['--color-text'], $tokens['--color-surface-emphasis']), 'text on surface-emphasis');
        $this->assertGreaterThanOrEqual(4.5, $this->contrast($tokens['--color-text-inverse'], $tokens['--color-action']), 'text-inverse (white) on action -- primary button pair');
        $this->assertGreaterThanOrEqual(3.0, $this->contrast($tokens['--color-focus'], $tokens['--color-canvas']), 'focus ring on canvas -- non-text contrast floor');

        // Deliberately below 4.5:1: spec §3.3 forbids --color-action as small
        // body text/standalone-link color on canvas. If a palette change ever
        // pushes this above 4.5:1, update this assertion deliberately (and
        // note why in docs/visual-identity.md), not by accident.
        $this->assertLessThan(4.5, $this->contrast($tokens['--color-action'], $tokens['--color-canvas']), 'action on canvas must stay below the normal-text AA threshold');
    }

    private function themeCss(): string
    {
        return (string) file_get_contents(self::THEME_CSS_PATH);
    }

    /** @return array<string, string> token name => #rrggbb */
    private function parseColorTokens(): array
    {
        $css = $this->themeCss();
        $tokens = [];
        if (preg_match_all('/(--color-[a-z-]+)\s*:\s*(#[0-9a-fA-F]{6})\s*;/', $css, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $tokens[$match[1]] = strtolower($match[2]);
            }
        }

        return $tokens;
    }

    private function contrast(string $hexA, string $hexB): float
    {
        $lumA = $this->relativeLuminance($hexA);
        $lumB = $this->relativeLuminance($hexB);
        $lighter = max($lumA, $lumB);
        $darker = min($lumA, $lumB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $linearize = static function (float $c): float {
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $linearize($r) + 0.7152 * $linearize($g) + 0.0722 * $linearize($b);
    }
}
