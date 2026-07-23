<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit;

use GrandpaSSOn\Config\ConfigLoader;
use PHPUnit\Framework\TestCase;

final class ConfigLoaderTest extends TestCase
{
    private string $tmpEnv;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->tmpEnv = sys_get_temp_dir() . '/grandpasson-env-' . uniqid('', true);
        foreach ($this->processKeys() as $key) {
            $this->envBackup[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        if (is_file($this->tmpEnv)) {
            unlink($this->tmpEnv);
        }
        foreach ($this->envBackup as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    public function testLoadsTypedConfigFromEnvFile(): void
    {
        $this->writeEnv(<<<'ENV'
APP_ENV=dev
BROKER_BASE_URL=http://localhost:8080/
BROKER_NAME=GrandpaSSOn
SESSION_COOKIE_NAME=AUTHSESSID
SESSION_COOKIE_SECURE=false
SESSION_TTL_MINUTES=480
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=grandpasson
DB_USER=grandpasson
DB_PASSWORD=secret
ALLOWED_EMAIL_DOMAINS=Example.com, Contoso.COM ,
MIGRATE_TOKEN=tok
ADMIN_API_TOKEN=admin-secret
MS_TENANT_ID=tenant-1
GOOGLE_CLIENT_ID=g-id
ENV);

        $config = ConfigLoader::load($this->tmpEnv);

        $this->assertSame('dev', $config['app_env']);
        $this->assertFalse($config['force_https']);
        $this->assertSame('http://localhost:8080', $config['broker']['base_url']);
        $this->assertSame('AUTHSESSID', $config['session']['cookie_name']);
        $this->assertFalse($config['session']['secure']);
        $this->assertSame(480, $config['session']['ttl_minutes']);
        $this->assertSame('127.0.0.1', $config['db']['host']);
        $this->assertSame('secret', $config['db']['password']);
        $this->assertSame(['example.com', 'contoso.com'], $config['allowed_email_domains']);
        $this->assertSame('tok', $config['migrate_token']);
        $this->assertSame('admin-secret', $config['admin_api_token']);
        $this->assertSame('tenant-1', $config['providers']['microsoft']['tenant_id']);
        $this->assertSame('g-id', $config['providers']['google']['client_id']);
        $this->assertContains('openid', $config['providers']['google']['scopes']);
        $this->assertContains('read:user', $config['providers']['github']['scopes']);
        $this->assertSame(900, $config['tokens']['access_ttl_seconds']);
        $this->assertSame(3600, $config['tokens']['access_ttl_max_seconds']);
        $this->assertSame(90, $config['audit']['retention_days']);
        $this->assertSame(60, $config['rate_limit']['oauth_max']);
        $this->assertSame(60, $config['rate_limit']['oauth_window_seconds']);
        $this->assertSame(15, $config['rate_limit']['login_max']);
        $this->assertSame(300, $config['rate_limit']['login_window_seconds']);
        $this->assertSame(900, $config['rate_limit']['login_lockout_seconds']);
    }

    public function testRateLimitOverrides(): void
    {
        $this->writeEnv($this->minimalEnv() . <<<'ENV'

RATE_LIMIT_OAUTH_MAX=10
RATE_LIMIT_OAUTH_WINDOW_SECONDS=30
RATE_LIMIT_LOGIN_MAX=5
RATE_LIMIT_LOGIN_WINDOW_SECONDS=120
RATE_LIMIT_LOGIN_LOCKOUT_SECONDS=600
ENV);

        $config = ConfigLoader::load($this->tmpEnv);

        $this->assertSame(10, $config['rate_limit']['oauth_max']);
        $this->assertSame(30, $config['rate_limit']['oauth_window_seconds']);
        $this->assertSame(5, $config['rate_limit']['login_max']);
        $this->assertSame(120, $config['rate_limit']['login_window_seconds']);
        $this->assertSame(600, $config['rate_limit']['login_lockout_seconds']);
    }

    public function testProcessEnvOverridesFile(): void
    {
        $this->writeEnv($this->minimalEnv());
        putenv('DB_HOST=mysql');
        putenv('ALLOWED_EMAIL_DOMAINS=override.test');

        $config = ConfigLoader::load($this->tmpEnv);

        $this->assertSame('mysql', $config['db']['host']);
        $this->assertSame(['override.test'], $config['allowed_email_domains']);
    }

    public function testV1TokenAndAuditDefaultsAndOverrides(): void
    {
        $this->writeEnv($this->minimalEnv() . <<<'ENV'

ACCESS_TOKEN_TTL_SECONDS=600
ACCESS_TOKEN_TTL_MAX_SECONDS=1800
AUDIT_RETENTION_DAYS=30
ENV);

        $config = ConfigLoader::load($this->tmpEnv);
        $this->assertSame(600, $config['tokens']['access_ttl_seconds']);
        $this->assertSame(1800, $config['tokens']['access_ttl_max_seconds']);
        $this->assertSame(30, $config['audit']['retention_days']);
    }

    public function testAccessTokenTtlIsClampedToMax(): void
    {
        $this->writeEnv($this->minimalEnv() . <<<'ENV'

ACCESS_TOKEN_TTL_SECONDS=99999
ACCESS_TOKEN_TTL_MAX_SECONDS=1800
ENV);

        $config = ConfigLoader::load($this->tmpEnv);
        $this->assertSame(1800, $config['tokens']['access_ttl_seconds']);
        $this->assertSame(1800, $config['tokens']['access_ttl_max_seconds']);
    }

    public function testInvalidOptionalIntsFallBackToDefaults(): void
    {
        $this->writeEnv($this->minimalEnv() . <<<'ENV'

ACCESS_TOKEN_TTL_SECONDS=nope
ACCESS_TOKEN_TTL_MAX_SECONDS=0
AUDIT_RETENTION_DAYS=-5
ENV);

        $config = ConfigLoader::load($this->tmpEnv);
        $this->assertSame(900, $config['tokens']['access_ttl_seconds']);
        $this->assertSame(3600, $config['tokens']['access_ttl_max_seconds']);
        $this->assertSame(90, $config['audit']['retention_days']);
    }

    public function testProdDefaultsForceHttpsAndSecureCookie(): void
    {
        $this->writeEnv(str_replace('APP_ENV=dev', 'APP_ENV=prod', $this->minimalEnv()));

        $config = ConfigLoader::load($this->tmpEnv);

        $this->assertSame('prod', $config['app_env']);
        $this->assertTrue($config['force_https']);
        $this->assertTrue($config['session']['secure']);
    }

    public function testForceHttpsOverrideOnDev(): void
    {
        $this->writeEnv($this->minimalEnv() . "\nFORCE_HTTPS=true\n");

        $config = ConfigLoader::load($this->tmpEnv);

        $this->assertTrue($config['force_https']);
        $this->assertTrue($config['session']['secure']);
    }

    public function testForceHttpsFalseOverridesProd(): void
    {
        $env = str_replace('APP_ENV=dev', 'APP_ENV=prod', $this->minimalEnv());
        $this->writeEnv($env . "\nFORCE_HTTPS=false\nSESSION_COOKIE_SECURE=false\n");

        $config = ConfigLoader::load($this->tmpEnv);

        $this->assertFalse($config['force_https']);
        $this->assertFalse($config['session']['secure']);
    }

    public function testMissingRequiredFailsFast(): void
    {
        file_put_contents($this->tmpEnv, "APP_ENV=dev\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required env vars');
        ConfigLoader::load($this->tmpEnv);
    }

    public function testSpecExtensionDocIsPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $this->assertFileExists($root . '/docs/grandpasson-spec-v1-extension.md');
        $this->assertFileExists(
            $root . '/docs/plans/2026-07-22-001-feat-v1-p0-tenancy-machine-identity-plan.md'
        );
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->tmpEnv, $contents);
    }

    private function minimalEnv(): string
    {
        return <<<'ENV'
APP_ENV=dev
BROKER_BASE_URL=http://localhost:8080
BROKER_NAME=GrandpaSSOn
SESSION_COOKIE_NAME=AUTHSESSID
SESSION_COOKIE_SECURE=false
SESSION_TTL_MINUTES=480
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=grandpasson
DB_USER=grandpasson
DB_PASSWORD=secret
ENV;
    }

    /** @return list<string> */
    private function processKeys(): array
    {
        return [
            'APP_ENV', 'FORCE_HTTPS', 'BROKER_BASE_URL', 'BROKER_NAME',
            'SESSION_COOKIE_NAME', 'SESSION_COOKIE_SECURE', 'SESSION_TTL_MINUTES',
            'READER_SESSION_COOKIE_NAME',
            'DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
            'ALLOWED_EMAIL_DOMAINS', 'MIGRATE_TOKEN', 'ADMIN_API_TOKEN',
            'ACCESS_TOKEN_TTL_SECONDS', 'ACCESS_TOKEN_TTL_MAX_SECONDS', 'AUDIT_RETENTION_DAYS',
            'RATE_LIMIT_OAUTH_MAX', 'RATE_LIMIT_OAUTH_WINDOW_SECONDS',
            'RATE_LIMIT_LOGIN_MAX', 'RATE_LIMIT_LOGIN_WINDOW_SECONDS', 'RATE_LIMIT_LOGIN_LOCKOUT_SECONDS',
            'JWT_ACCESS_TOKEN_ENABLED', 'JWT_HMAC_SECRET', 'JWT_KEY_ENCRYPTION_SECRET',
            'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET', 'GOOGLE_REDIRECT_URI',
            'MS_CLIENT_ID', 'MS_CLIENT_SECRET', 'MS_TENANT_ID', 'MS_REDIRECT_URI',
            'GITHUB_CLIENT_ID', 'GITHUB_CLIENT_SECRET', 'GITHUB_REDIRECT_URI',
        ];
    }
}
