<?php

declare(strict_types=1);

namespace GrandpaSSOn\Config;

final class ConfigLoader
{
    /**
     * @return array{
     *   app_env: string,
     *   broker: array{name: string, base_url: string},
     *   session: array{cookie_name: string, secure: bool, ttl_minutes: int},
     *   db: array{host: string, port: int, name: string, user: string, password: string},
     *   allowed_email_domains: list<string>,
     *   migrate_token: string,
     *   providers: array<string, array{client_id: string, client_secret: string, redirect_uri: string, scopes: list<string>, tenant_id?: string}>
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

        return [
            'app_env' => $env['APP_ENV'],
            'broker' => [
                'name' => $env['BROKER_NAME'],
                'base_url' => rtrim($env['BROKER_BASE_URL'], '/'),
            ],
            'session' => [
                'cookie_name' => $env['SESSION_COOKIE_NAME'],
                'secure' => filter_var($env['SESSION_COOKIE_SECURE'], FILTER_VALIDATE_BOOLEAN),
                'ttl_minutes' => (int) $env['SESSION_TTL_MINUTES'],
            ],
            'db' => [
                'host' => $env['DB_HOST'],
                'port' => (int) $env['DB_PORT'],
                'name' => $env['DB_NAME'],
                'user' => $env['DB_USER'],
                'password' => $env['DB_PASSWORD'],
            ],
            'allowed_email_domains' => $domains,
            'migrate_token' => $env['MIGRATE_TOKEN'] ?? '',
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
        ];
    }

    /**
     * @return list<string>
     */
    private static function knownKeys(): array
    {
        return [
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
            'ALLOWED_EMAIL_DOMAINS',
            'MIGRATE_TOKEN',
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
        ];
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
