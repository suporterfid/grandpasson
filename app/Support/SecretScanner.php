<?php

declare(strict_types=1);

namespace GrandpaSSOn\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * S1 gate: fail builds when credential-like literals appear in app/ or the release zip.
 */
final class SecretScanner
{
    /**
     * Scan a filesystem directory (typically app/).
     *
     * @return list<string> Findings as "relative/path: snippet"
     */
    public static function scanDirectory(string $root): array
    {
        if (!is_dir($root)) {
            throw new \InvalidArgumentException('Not a directory: ' . $root);
        }

        $findings = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || !self::isScannableFile($file->getFilename())) {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($root, '/\\')))), '/');
            $contents = (string) file_get_contents($file->getPathname());
            foreach (self::findInContent($contents) as $hit) {
                $findings[] = $relative . ': ' . $hit;
            }
        }

        return $findings;
    }

    /**
     * Scan a release zip for secrets in first-party trees and forbidden env files.
     * Skips vendor/ to avoid third-party noise; still blocks shipping a real `.env`.
     *
     * @return list<string>
     */
    public static function scanReleaseZip(string $zipPath): array
    {
        if (!is_file($zipPath)) {
            throw new \InvalidArgumentException('Zip not found: ' . $zipPath);
        }
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('ext-zip (ZipArchive) is required to scan release zips');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new \RuntimeException('Unable to open zip: ' . $zipPath);
        }

        $findings = [];
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $normalized = ltrim(str_replace('\\', '/', $name), '/');
                if ($normalized === '' || str_ends_with($normalized, '/')) {
                    continue;
                }

                if (self::isForbiddenEnvPath($normalized)) {
                    $findings[] = $normalized . ': forbidden env file present in release zip';
                    continue;
                }

                if (!self::shouldScanZipEntry($normalized)) {
                    continue;
                }

                $contents = (string) $zip->getFromIndex($i);
                foreach (self::findInContent($contents) as $hit) {
                    $findings[] = $normalized . ': ' . $hit;
                }
            }
        } finally {
            $zip->close();
        }

        return $findings;
    }

    /**
     * @return list<string>
     */
    public static function findInContent(string $contents): array
    {
        $hits = [];

        // Full opaque tokens (prefix + long random), not the bare PREFIX constant.
        if (preg_match_all('/gpat_live_[A-Za-z0-9_-]{20,}/', $contents, $m) > 0) {
            foreach (array_unique($m[0]) as $token) {
                // Allow documenting the shape with obvious placeholders.
                if (str_contains($token, '<') || str_contains(strtoupper($token), 'EXAMPLE')) {
                    continue;
                }
                $hits[] = 'opaque token literal: ' . self::redact($token);
            }
        }

        if (preg_match('/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/', $contents) === 1) {
            $hits[] = 'private key PEM block';
        }

        if (preg_match('/\bAKIA[0-9A-Z]{16}\b/', $contents) === 1) {
            $hits[] = 'AWS access key id literal';
        }

        if (preg_match_all(
            '/\b(client_secret|api_key|secret_key|access_token|private_key)\b\s*[=:]\s*[\'"]([^\'"]{12,})[\'"]/i',
            $contents,
            $assign,
            PREG_SET_ORDER
        ) > 0) {
            foreach ($assign as $row) {
                $value = $row[2];
                if (self::isPlaceholderSecret($value)) {
                    continue;
                }
                $hits[] = 'hardcoded ' . strtolower($row[1]) . ' assignment';
            }
        }

        return array_values(array_unique($hits));
    }

    private static function shouldScanZipEntry(string $normalized): bool
    {
        if (str_starts_with($normalized, 'vendor/')) {
            return false;
        }
        if (!self::isScannableFile(basename($normalized))) {
            return false;
        }
        if (!str_contains($normalized, '/')) {
            return true;
        }

        foreach (['app/', 'cron/', 'public_html/'] as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private static function isForbiddenEnvPath(string $normalized): bool
    {
        $base = basename($normalized);
        if ($base === '.env.example') {
            return false;
        }

        return $base === '.env' || str_starts_with($base, '.env.');
    }

    private static function isScannableFile(string $filename): bool
    {
        if ($filename === '.env' || $filename === '.env.example' || str_starts_with($filename, '.env.')) {
            return true;
        }

        $lower = strtolower($filename);
        foreach (['.php', '.json', '.yml', '.yaml', '.xml', '.md', '.txt', '.sh', '.ini'] as $ext) {
            if (str_ends_with($lower, $ext)) {
                return true;
            }
        }

        return false;
    }

    private static function isPlaceholderSecret(string $value): bool
    {
        $lower = strtolower($value);

        return $lower === ''
            || str_contains($lower, 'changeme')
            || str_contains($lower, 'example')
            || str_contains($lower, 'placeholder')
            || str_contains($lower, 'your-')
            || str_contains($lower, 'xxx')
            || preg_match('/^\$\{?[A-Z0-9_]+\}?$/', $value) === 1;
    }

    private static function redact(string $value): string
    {
        if (strlen($value) <= 16) {
            return '(redacted)';
        }

        return substr($value, 0, 12) . '…';
    }
}
