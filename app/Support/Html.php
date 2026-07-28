<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

/**
 * Base-path-aware HTML shell helper (VI3, #90). Centralizes the doctype,
 * theme stylesheet link, and base-path URL construction so every
 * browser-facing controller agrees with public_html/index.php's subpath
 * routing instead of hand-rolling markup.
 */
final class Html
{
    /**
     * Base path derived from BROKER_BASE_URL, mirroring the prefix
     * public_html/index.php strips off the incoming request URI. Empty
     * string at web root; a normalized (no trailing slash) path segment
     * otherwise, e.g. '/sso'.
     *
     * @param array<string, mixed> $config
     */
    public static function basePath(array $config): string
    {
        $baseUrl = (string) ($config['broker']['base_url'] ?? '');
        $path = parse_url($baseUrl, PHP_URL_PATH) ?: '';

        if ($path === '' || $path === '/') {
            return '';
        }

        return rtrim($path, '/');
    }

    /** Base-path-prefixed URL for a file under public_html/assets/. */
    public static function asset(array $config, string $relative): string
    {
        return self::basePath($config) . '/assets/' . ltrim($relative, '/');
    }

    /**
     * VI10 (#97): every asset this markup references (theme.css, fonts,
     * admin.js, favicon.svg) is self-hosted and same-origin, and no
     * controller emits inline <style>/<script> or inline event handlers —
     * so the policy can be this strict without an 'unsafe-inline' escape
     * hatch. Public so tests can assert on the shipped policy directly.
     */
    public const CSP = "default-src 'self'; style-src 'self'; script-src 'self'; "
        . "font-src 'self'; img-src 'self'; connect-src 'self'; form-action 'self'; "
        . "frame-ancestors 'none'; base-uri 'none'; object-src 'none'";

    /**
     * Emits doctype through <body> open, including the theme stylesheet
     * link and the Content-Security-Policy header (VI10, #97). Callers
     * must close with pageEnd().
     */
    public static function pageStart(array $config, string $title, string $bodyClass = ''): string
    {
        header('Content-Security-Policy: ' . self::CSP);

        $themeHref = self::e(self::asset($config, 'theme.css'));
        $faviconHref = self::e(self::asset($config, 'favicon.svg'));
        $safeTitle = self::e($title);
        $classAttr = $bodyClass !== '' ? ' class="' . self::e($bodyClass) . '"' : '';

        return <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{$safeTitle}</title>
        <link rel="icon" href="{$faviconHref}" type="image/svg+xml">
        <link rel="stylesheet" href="{$themeHref}">
        </head>
        <body{$classAttr}>
        HTML;
    }

    public static function pageEnd(): string
    {
        return '</body></html>';
    }

    /** htmlspecialchars with ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'. */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
