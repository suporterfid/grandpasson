<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Support\SecretScanner;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class SecretScannerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/gp-s1-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    public function testPlantedSecretFixtureFailsScan(): void
    {
        $planted = 'gpat_live_PLANTED_S1_FIXTURE_DO_NOT_SHIP_000000000000';
        file_put_contents(
            $this->tmpDir . '/leak.php',
            "<?php\n\$token = '{$planted}';\n"
        );

        $findings = SecretScanner::scanDirectory($this->tmpDir);
        $this->assertNotSame([], $findings, 'S1 scanner must fail on planted opaque token');
        $this->assertStringContainsString('opaque token literal', implode("\n", $findings));
    }

    public function testProductionAppTreeIsClean(): void
    {
        $app = dirname(__DIR__, 2) . '/app';
        $findings = SecretScanner::scanDirectory($app);
        $this->assertSame([], $findings, "app/ must not contain secrets:\n" . implode("\n", $findings));
    }

    public function testReleaseZipHasNoEnvSecretsWhenPresent(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip not available');
        }

        $zipPath = dirname(__DIR__, 2) . '/grandpasson-release.zip';
        if (!is_file($zipPath)) {
            $this->markTestSkipped('grandpasson-release.zip not built yet (run make build)');
        }

        $findings = SecretScanner::scanReleaseZip($zipPath);
        $this->assertSame([], $findings, "release zip must not contain secrets:\n" . implode("\n", $findings));
    }

    public function testSyntheticZipWithEnvIsRejected(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip not available');
        }

        $zipPath = $this->tmpDir . '/bad.zip';
        $zip = new ZipArchive();
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE));
        $zip->addFromString('.env', "DB_PASSWORD=supersecretvalue\n");
        $zip->addFromString('.env.production', "API_KEY=anothersecret\n");
        $zip->addFromString(
            'app/Leak.php',
            "<?php\n\$client_secret = \"hardcodedsecret99\";\n"
        );
        $zip->addFromString(
            'cron/leak.php',
            "<?php\n\$api_key = \"cronsecretvalue1\";\n"
        );
        $zip->close();

        $findings = SecretScanner::scanReleaseZip($zipPath);
        $this->assertNotSame([], $findings);
        $joined = implode("\n", $findings);
        $this->assertStringContainsString('.env', $joined);
        $this->assertStringContainsString('.env.production', $joined);
        $this->assertStringContainsString('hardcoded client_secret', $joined);
        $this->assertStringContainsString('cron/leak.php', $joined);
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeTree($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
