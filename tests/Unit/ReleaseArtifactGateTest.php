<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Support\SecretScanner;
use PHPUnit\Framework\TestCase;

/**
 * U11 regression gate: release artifact constraints remain enforceable.
 */
final class ReleaseArtifactGateTest extends TestCase
{
    public function testAppTreeHasNoCredentialLiterals(): void
    {
        $findings = SecretScanner::scanDirectory(dirname(__DIR__, 2) . '/app');
        $this->assertSame([], $findings, implode("\n", $findings));
    }

    public function testFrontControllerUsesSharedAppRoutes(): void
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/public_html/index.php');
        $this->assertStringContainsString('AppRoutes::register', $index);
        $this->assertStringNotContainsString("\$router->post('/oauth/token'", $index);
    }

    public function testDockerBuildScriptHasLfPortableShebang(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__, 2) . '/docker/build/build.sh');

        $this->assertStringNotContainsString("\r", $script, 'Docker executes the script in Linux and cannot run CRLF shebangs.');
        $this->assertStringStartsWith("#!/bin/sh\n", $script);
    }

    public function testReleaseZipCleanWhenPresent(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('ext-zip not available');
        }
        $zipPath = dirname(__DIR__, 2) . '/grandpasson-release.zip';
        if (!is_file($zipPath)) {
            $this->markTestSkipped('grandpasson-release.zip not built (make build)');
        }

        $findings = SecretScanner::scanReleaseZip($zipPath);
        $this->assertSame([], $findings, implode("\n", $findings));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath) === true);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = (string) $zip->getNameIndex($i);
        }
        $zip->close();
        $joined = implode("\n", $names);
        $this->assertStringNotContainsString("\ntests/", "\n" . $joined);
        $this->assertStringNotContainsString("\ndocker/", "\n" . $joined);
        $this->assertStringContainsString('app/', $joined);
        $this->assertStringContainsString('cron/', $joined);
        $this->assertStringContainsString('public_html/', $joined);

        // VI11 (#98): identity assets ship, and the repo-only brand source tree does not.
        $this->assertContains('public_html/assets/theme.css', $names, 'theme.css must ship in the release zip');
        $this->assertContains('public_html/assets/favicon.svg', $names, 'favicon.svg must ship in the release zip');
        $this->assertStringNotContainsString("\nassets/brand/", "\n" . $joined, 'assets/brand/ is repo-only and must not ship');
    }
}
