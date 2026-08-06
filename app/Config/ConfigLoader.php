<?php

declare(strict_types=1);

namespace GrandpaSSOn\Config;

final class ConfigLoader
{
    /**
     * @return array{
     *   app_env: string,
     *   force_https: bool,
     *   broker: array{name: string, base_url: string},
     *   session: array{cookie_name: string, secure: bool, ttl_minutes: int, reader_cookie_name: string},
     *   db: array{host: string, port: int, name: string, user: string, password: string},
     *   allowed_email_domains: list<string>,
     *   admin_notification_emails: list<string>,
     *   migrate_token: string,
     *   cron_token: string,
     *   admin_api_token: string,
     *   tokens: array{access_ttl_seconds: int, access_ttl_max_seconds: int},
     *   audit: array{retention_days: int},
     *   rate_limit: array{oauth_max: int, oauth_window_seconds: int, login_max: int, login_window_seconds: int, login_lockout_seconds: int, email_otp_start_max: int, email_otp_start_window_seconds: int, email_otp_verify_max: int, email_otp_verify_window_seconds: int},
     *   jwt: array{enabled: bool, hmac_secret: string, key_encryption_secret: string},
     *   providers: array<string, array{client_id: string, client_secret: string, redirect_uri: string, scopes: list<string>, tenant_id?: string}>,
     *   mail: array{transport: string, from_address: string, from_name: string, smtp_host: string, smtp_port: int, smtp_username: string, smtp_password: string, smtp_encryption: string},
     *   email_otp: array{ttl_seconds: int, code_length: int, max_attempts: int}
     * }
     */
    public static function load(?string $envPath = null): array
    {
        $root = dirname(__DIR__, 2);
        $envPath ??= $root . '/.env';

        $env = [];
        if (is_readable($envPath)) {
            $env = self::parseEnvFile($envPath);
        }

        // Process environment (e.g. docker compose env_file) overrides file values.
        foreach (self::knownKeys() as $key) {
            $fromProcess = getenv($key);
            if ($fromProcess !== false) {
                $env[$key] = $fromProcess;
            }
        }

        $required = [
            'APP_ENV',
            'BROKER_BASE_URL',
            'BROKER_NAME',
            'SESSION_COOKIE_NAME',
            'SESSION_COOKIE_SECURE',
            'SESSION_TTL_MINUTES',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
        ];

        $missing = [];
        foreach ($required as $key) {
            if (!array_key_exists($key, $env) || $env[$key] === '') {
                $missing[] = $key;
            }
        }
        if ($missing !== []) {
            $hint = is_readable($envPath)
                ? ''
                : ' Copy .env.example to .env (or inject the vars into the process environment).';
            throw new \RuntimeException(
                'Missing required env vars: ' . implode(', ', $missing) . '.' . $hint
            );
        }

        $domainsRaw = $env['ALLOWED_EMAIL_DOMAINS'] ?? '';
        $domains = array_values(array_filter(
            array_map(
                static fn (string $d): string => strtolower(trim($d)),
                explode(',', $domainsRaw)
            ),
            static fn (string $d): bool => $d !== ''
        ));

        $adminEmailsRaw = $env['ADMIN_NOTIFICATION_EMAILS'] ?? '';
        $adminEmails = array_values(array_filter(
            array_map(
                static fn (string $e): string => strtolower(trim($e)),
                explode(',', $adminEmailsRaw)
            ),
            static fn (string $e): bool => $e !== ''
        ));

        $tokenTtlMax = self::positiveInt($env['ACCESS_TOKEN_TTL_MAX_SECONDS'] ?? '', 3600);
        $tokenTtl = self::positiveInt($env['ACCESS_TOKEN_TTL_SECONDS'] ?? '', 900);
        if ($tokenTtl > $tokenTtlMax) {
            $tokenTtl = $tokenTtlMax;
        }

        $appEnv = (string) $env['APP_ENV'];
        $forceHttps = self::resolveForceHttps($env, $appEnv);
        $cookieSecure = filter_var($env['SESSION_COOKIE_SECURE'], FILTER_VALIDATE_BOOLEAN);
        // S7: when HTTPS is enforced, cookies must be Secure even if the env flag was left false.
        if ($forceHttps) {
            $cookieSecure = true;
        }

        return [
            'app_env' => $appEnv,
            'force_https' => $forceHttps,
            'broker' => [
                'name' => $env['BROKER_NAME'],
                'base_url' => rtrim($env['BROKER_BASE_URL'], '/'),
            ],
            'session' => [
                'cookie_name' => $env['SESSION_COOKIE_NAME'],
                'secure' => $cookieSecure,
                'ttl_minutes' => (int) $env['SESSION_TTL_MINUTES'],
                'reader_cookie_name' => $env['READER_SESSION_COOKIE_NAME'] ?? 'GPSREADER',
            ],
            'db' => [
                'host' => $env['DB_HOST'],
                'port' => (int) $env['DB_PORT'],
                'name' => $env['DB_NAME'],
                'user' => $env['DB_USER'],
                'password' => $env['DB_PASSWORD'],
                'prefix' => $env['DB_PREFIX'] ?? '',
            ],
            'allowed_email_domains' => $domains,
            'admin_notification_emails' => $adminEmails,
            'migrate_token' => $env['MIGRATE_TOKEN'] ?? '',
            'cron_token' => $env['CRON_TOKEN'] ?? '',
            'admin_api_token' => $env['ADMIN_API_TOKEN'] ?? '',
            'tokens' => [
                'access_ttl_seconds' => $tokenTtl,
                'access_ttl_max_seconds' => $tokenTtlMax,
            ],
            'audit' => [
                'retention_days' => self::positiveInt($env['AUDIT_RETENTION_DAYS'] ?? '', 90),
            ],
            'rate_limit' => [
                'oauth_max' => self::positiveInt($env['RATE_LIMIT_OAUTH_MAX'] ?? '', 60),
                'oauth_window_seconds' => self::positiveInt($env['RATE_LIMIT_OAUTH_WINDOW_SECONDS'] ?? '', 60),
                'login_max' => self::positiveInt($env['RATE_LIMIT_LOGIN_MAX'] ?? '', 15),
                'login_window_seconds' => self::positiveInt($env['RATE_LIMIT_LOGIN_WINDOW_SECONDS'] ?? '', 300),
                'login_lockout_seconds' => self::positiveInt($env['RATE_LIMIT_LOGIN_LOCKOUT_SECONDS'] ?? '', 900),
                'email_otp_start_max' => self::positiveInt($env['RATE_LIMIT_EMAIL_OTP_START_MAX'] ?? '', 5),
                'email_otp_start_window_seconds' => self::positiveInt($env['RATE_LIMIT_EMAIL_OTP_START_WINDOW_SECONDS'] ?? '', 900),
                'email_otp_verify_max' => self::positiveInt($env['RATE_LIMIT_EMAIL_OTP_VERIFY_MAX'] ?? '', 10),
                'email_otp_verify_window_seconds' => self::positiveInt($env['RATE_LIMIT_EMAIL_OTP_VERIFY_WINDOW_SECONDS'] ?? '', 900),
            ],
            'jwt' => [
                'enabled' => filter_var($env['JWT_ACCESS_TOKEN_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN),
                'hmac_secret' => (string) ($env['JWT_HMAC_SECRET'] ?? ''),
                'key_encryption_secret' => (string) ($env['JWT_KEY_ENCRYPTION_SECRET'] ?? ''),
            ],
            'providers' => [
                'google' => [
                    'client_id' => $env['GOOGLE_CLIENT_ID'] ?? '',
                    'client_secret' => $env['GOOGLE_CLIENT_SECRET'] ?? '',
                    'redirect_uri' => $env['GOOGLE_REDIRECT_URI'] ?? '',
                    'scopes' => ['openid', 'email', 'profile'],
                ],
                'microsoft' => [
                    'client_id' => $env['MS_CLIENT_ID'] ?? '',
                    'client_secret' => $env['MS_CLIENT_SECRET'] ?? '',
                    'redirect_uri' => $env['MS_REDIRECT_URI'] ?? '',
                    'tenant_id' => $env['MS_TENANT_ID'] ?? '',
                    'scopes' => ['openid', 'email', 'profile'],
                ],
                'github' => [
                    'client_id' => $env['GITHUB_CLIENT_ID'] ?? '',
                    'client_secret' => $env['GITHUB_CLIENT_SECRET'] ?? '',
                    'redirect_uri' => $env['GITHUB_REDIRECT_URI'] ?? '',
                    'scopes' => ['read:user', 'user:email'],
                ],
            ],
            'mail' => [
                'transport' => $env['MAIL_TRANSPORT'] ?? 'sendmail',
                'from_address' => $env['MAIL_FROM_ADDRESS'] ?? '',
                'from_name' => ($env['MAIL_FROM_NAME'] ?? '') !== '' ? $env['MAIL_FROM_NAME'] : $env['BROKER_NAME'],
                'smtp_host' => $env['SMTP_HOST'] ?? '',
                'smtp_port' => self::positiveInt($env['SMTP_PORT'] ?? '', 587),
                'smtp_username' => $env['SMTP_USERNAME'] ?? '',
                'smtp_password' => $env['SMTP_PASSWORD'] ?? '',
                'smtp_encryption' => $env['SMTP_ENCRYPTION'] ?? 'tls',
            ],
            'email_otp' => [
                'ttl_seconds' => self::positiveInt($env['EMAIL_OTP_TTL_SECONDS'] ?? '', 600),
                'code_length' => self::positiveInt($env['EMAIL_OTP_CODE_LENGTH'] ?? '', 6),
                'max_attempts' => self::positiveInt($env['EMAIL_OTP_MAX_ATTEMPTS'] ?? '', 5),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private static function knownKeys(): array
    {
        return [
            'APP_ENV',
            'FORCE_HTTPS',
            'BROKER_BASE_URL',
            'BROKER_NAME',
            'SESSION_COOKIE_NAME',
            'SESSION_COOKIE_SECURE',
            'SESSION_TTL_MINUTES',
            'READER_SESSION_COOKIE_NAME',
            'DB_HOST',
            'DB_PORT',
            'DB_NAME',
            'DB_USER',
            'DB_PASSWORD',
            'ALLOWED_EMAIL_DOMAINS',
            'ADMIN_NOTIFICATION_EMAILS',
            'MIGRATE_TOKEN',
            'CRON_TOKEN',
            'ADMIN_API_TOKEN',
            'ACCESS_TOKEN_TTL_SECONDS',
            'ACCESS_TOKEN_TTL_MAX_SECONDS',
            'AUDIT_RETENTION_DAYS',
            'RATE_LIMIT_OAUTH_MAX',
            'RATE_LIMIT_OAUTH_WINDOW_SECONDS',
            'RATE_LIMIT_LOGIN_MAX',
            'RATE_LIMIT_LOGIN_WINDOW_SECONDS',
            'RATE_LIMIT_LOGIN_LOCKOUT_SECONDS',
            'RATE_LIMIT_EMAIL_OTP_START_MAX',
            'RATE_LIMIT_EMAIL_OTP_START_WINDOW_SECONDS',
            'RATE_LIMIT_EMAIL_OTP_VERIFY_MAX',
            'RATE_LIMIT_EMAIL_OTP_VERIFY_WINDOW_SECONDS',
            'JWT_ACCESS_TOKEN_ENABLED',
            'JWT_HMAC_SECRET',
            'JWT_KEY_ENCRYPTION_SECRET',
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET',
            'GOOGLE_REDIRECT_URI',
            'MS_CLIENT_ID',
            'MS_CLIENT_SECRET',
            'MS_TENANT_ID',
            'MS_REDIRECT_URI',
            'GITHUB_CLIENT_ID',
            'GITHUB_CLIENT_SECRET',
            'GITHUB_REDIRECT_URI',
            'MAIL_TRANSPORT',
            'MAIL_FROM_ADDRESS',
            'MAIL_FROM_NAME',
            'SMTP_HOST',
            'SMTP_PORT',
            'SMTP_USERNAME',
            'SMTP_PASSWORD',
            'SMTP_ENCRYPTION',
            'EMAIL_OTP_TTL_SECONDS',
            'EMAIL_OTP_CODE_LENGTH',
            'EMAIL_OTP_MAX_ATTEMPTS',
        ];
    }

    /**
     * FORCE_HTTPS=true|false overrides; otherwise enabled when APP_ENV=prod.
     *
     * @param array<string, string> $env
     */
    private static function resolveForceHttps(array $env, string $appEnv): bool
    {
        if (array_key_exists('FORCE_HTTPS', $env) && $env['FORCE_HTTPS'] !== '') {
            return filter_var($env['FORCE_HTTPS'], FILTER_VALIDATE_BOOLEAN);
        }

        return strtolower($appEnv) === 'prod';
    }

    private static function positiveInt(string $raw, int $default): int
    {
        if ($raw === '' || !ctype_digit($raw)) {
            return $default;
        }
        $value = (int) $raw;

        return $value >= 1 ? $value : $default;
    }

    /**
     * @return array<string, string>
     */
    public static function parseEnvFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Unable to read env file: ' . $path);
        }

        $env = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }
            $env[$key] = $value;
        }

        return $env;
    }
}
