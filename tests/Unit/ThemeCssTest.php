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

    public function testSelectedUiFontIsLocallyDeclaredWithSwapAndRelativeUrl(): void
    {
        $css = (string) file_get_contents(self::PATH);

        $this->assertMatchesRegularExpression('/--font-ui:\\s*Inter,/', $css, 'Inter must remain the selected UI family');
        $this->assertMatchesRegularExpression(
            '/@font-face\\s*\\{(?:(?!\\}).)*font-family:\\s*[\'\"]Inter[\'\"]\\s*;(?:(?!\\}).)*font-display:\\s*swap\\s*;(?:(?!\\}).)*src:\\s*url\\([\'\"]fonts\/inter-[^\'\"]+\\.woff2[\'\"]\\)\\s*format\\([\'\"]woff2[\'\"]\\)\\s*;(?:(?!\\}).)*\\}/s',
            $css,
            'The selected Inter UI family must have a self-hosted WOFF2 @font-face with font-display: swap'
        );
    }

    public function testHasNoImportOrExternalUrl(): void
    {
        $css = $this->themeCss();
        $this->assertStringNotContainsString('@import', $css);
        $this->assertStringNotContainsString('http://', $css);
        $this->assertStringNotContainsString('https://', $css);
    }

    public function testProseShellMapsSafeAreasIntoEveryLogicalPaddingEdge(): void
    {
        $declarations = $this->declarationsForRule($this->themeCss(), '.prose');

        $this->assertSame(
            'max(var(--space-9), env(safe-area-inset-top)) max(var(--space-9), env(safe-area-inset-bottom))',
            $declarations['padding-block'] ?? null,
            'The deployed reading shell must avoid top and bottom display cutouts.'
        );
        $this->assertSame(
            'max(16px, var(--safe-inline-start)) max(16px, var(--safe-inline-end))',
            $declarations['padding-inline'] ?? null,
            'The deployed reading shell must map asymmetric inline safe areas through logical edges.'
        );

        $tabletDeclarations = $this->declarationsForRule(
            $this->atRuleBlock($this->themeCss(), '@media (min-width: 480px)'),
            '.prose'
        );
        $this->assertSame(
            'max(20px, var(--safe-inline-start)) max(20px, var(--safe-inline-end))',
            $tabletDeclarations['padding-inline'] ?? null,
            'The 480px reading shell must retain direction-aware 20px gutters.'
        );
    }

    public function testFormControlsFillTheirLineAndProvideA44PixelPointerTarget(): void
    {
        $declarations = $this->declarationsForRule(
            $this->themeCss(),
            "input,\nselect,\ntextarea,\n.control"
        );

        $this->assertSame('100%', $declarations['inline-size'] ?? null);
        $this->assertSame(
            '44px',
            $declarations['min-block-size'] ?? null,
            'Full-width form controls need a 44px minimum block target.'
        );
    }

    public function testButtonsHaveA44By44MinimumTarget(): void
    {
        $declarations = $this->declarationsForRule($this->themeCss(), ".btn,\n.button");

        $this->assertSame('inline-flex', $declarations['display'] ?? null);
        $this->assertSame('44px', $declarations['min-inline-size'] ?? null);
        $this->assertSame('44px', $declarations['min-block-size'] ?? null);
    }

    public function testStandaloneActionLinksHaveA44By44MinimumTarget(): void
    {
        $declarations = $this->declarationsForRule($this->themeCss(), '.action-link');

        $this->assertSame('inline-flex', $declarations['display'] ?? null);
        $this->assertSame('44px', $declarations['min-inline-size'] ?? null);
        $this->assertSame('44px', $declarations['min-block-size'] ?? null);
    }

    public function testInvalidControlsUseTheStrongSemanticBoundary(): void
    {
        $declarations = $this->declarationsForRule(
            $this->themeCss(),
            "input[aria-invalid='true'],\nselect[aria-invalid='true'],\ntextarea[aria-invalid='true']"
        );

        $this->assertSame(
            '2px solid var(--color-border-strong)',
            $declarations['border'] ?? null,
            'The essential invalid outline must use the canonical 3:1 control boundary.'
        );
    }

    public function testRtlSwapsTheExactPhysicalSafeAreaSources(): void
    {
        $declarations = $this->declarationsForRule($this->themeCss(), "[dir='rtl']");

        $this->assertSame('env(safe-area-inset-right)', $declarations['--safe-inline-start'] ?? null);
        $this->assertSame('env(safe-area-inset-left)', $declarations['--safe-inline-end'] ?? null);
    }

    public function testThemeSwitcherParticipatesInNarrowFlowAndIsFixedOnlyAtTheWiderBreakpoint(): void
    {
        $narrowDeclarations = $this->declarationsForRule($this->themeCss(), '.theme-switcher');

        $this->assertSame('static', $narrowDeclarations['position'] ?? null);
        $this->assertSame('fit-content', $narrowDeclarations['inline-size'] ?? null);
        $this->assertSame(
            'var(--space-4) max(var(--space-4), env(safe-area-inset-bottom))',
            $narrowDeclarations['margin-block'] ?? null
        );
        $this->assertSame(
            'auto max(var(--space-4), var(--safe-inline-end))',
            $narrowDeclarations['margin-inline'] ?? null
        );

        $wideDeclarations = $this->declarationsForRule(
            $this->atRuleBlock($this->themeCss(), '@media (min-width: 480px)'),
            '.theme-switcher'
        );

        $this->assertSame('fixed', $wideDeclarations['position'] ?? null);
        $this->assertSame('max(var(--space-4), var(--safe-inline-end))', $wideDeclarations['inset-inline-end'] ?? null);
        $this->assertSame('max(var(--space-4), env(safe-area-inset-bottom))', $wideDeclarations['inset-block-end'] ?? null);
    }

    public function testReducedMotionOverridesAnimationAndScrollingInItsMediaContract(): void
    {
        $declarations = $this->declarationsForRule(
            $this->atRuleBlock($this->themeCss(), '@media (prefers-reduced-motion: reduce)'),
            "*,\n*::before,\n*::after"
        );

        $this->assertSame('1ms !important', $declarations['animation-duration'] ?? null);
        $this->assertSame('1 !important', $declarations['animation-iteration-count'] ?? null);
        $this->assertSame('auto !important', $declarations['scroll-behavior'] ?? null);
        $this->assertSame('1ms !important', $declarations['transition-duration'] ?? null);
    }

    public function testForcedColorsUsesSystemAdjustmentAndRemovesShadowOnlyInItsMediaContract(): void
    {
        $media = $this->atRuleBlock($this->themeCss(), '@media (forced-colors: active)');
        $systemDeclarations = $this->declarationsForRule($media, "*,\n*::before,\n*::after");
        $surfaceDeclarations = $this->declarationsForRule($media, ".card,\n.btn,\n.button");

        $this->assertSame('auto', $systemDeclarations['forced-color-adjust'] ?? null);
        $this->assertSame('none', $surfaceDeclarations['box-shadow'] ?? null);
    }

    private function themeCss(): string
    {
        return (string) file_get_contents(self::PATH);
    }

    /** @return array<string, string> */
    private function declarationsForRule(string $css, string $selector): array
    {
        $block = $this->ruleBlock($css, $selector);
        preg_match_all('/(?<property>--[a-z0-9-]+|[a-z-]+)\s*:\s*(?<value>[^;{}]+);/i', $block, $matches, PREG_SET_ORDER);

        $declarations = [];
        foreach ($matches as $match) {
            $declarations[$match['property']] = trim($match['value']);
        }

        return $declarations;
    }

    private function ruleBlock(string $css, string $selector): string
    {
        $pattern = '/' . preg_quote($this->normalizeWhitespace($selector), '/') . '\\s*\\{/';
        $normalizedCss = $this->normalizeWhitespace($css);
        $this->assertMatchesRegularExpression($pattern, $normalizedCss, "Missing {$selector} rule");
        preg_match($pattern, $normalizedCss, $match, PREG_OFFSET_CAPTURE);

        return $this->blockFollowingOpeningBrace($normalizedCss, $match[0][1] + strlen($match[0][0]) - 1);
    }

    private function atRuleBlock(string $css, string $atRule): string
    {
        $normalizedCss = $this->normalizeWhitespace($css);
        $pattern = '/' . preg_quote($this->normalizeWhitespace($atRule), '/') . '\\s*\\{/';
        $this->assertMatchesRegularExpression($pattern, $normalizedCss, "Missing {$atRule} block");
        preg_match($pattern, $normalizedCss, $match, PREG_OFFSET_CAPTURE);

        return $this->blockFollowingOpeningBrace($normalizedCss, $match[0][1] + strlen($match[0][0]) - 1);
    }

    private function blockFollowingOpeningBrace(string $css, int $openingBraceOffset): string
    {
        $depth = 0;
        $length = strlen($css);
        for ($offset = $openingBraceOffset; $offset < $length; $offset++) {
            if ($css[$offset] === '{') {
                $depth++;
            } elseif ($css[$offset] === '}' && --$depth === 0) {
                return substr($css, $openingBraceOffset + 1, $offset - $openingBraceOffset - 1);
            }
        }

        $this->fail('Unclosed CSS block');
    }

    private function normalizeWhitespace(string $value): string
    {
        return trim((string) preg_replace('/\\s+/', ' ', $value));
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
