<?php

declare(strict_types=1);

/**
 * Production / shared-hosting migration entrypoint.
 *
 * CLI:  php cron/migrate.php
 * HTTP: only when MIGRATE_TOKEN is set and request provides matching ?token= or X-Migrate-Token.
 */

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';

try {
    $config = ConfigLoader::load();
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

if (!$isCli) {
    $token = $config['migrate_token'];
    if ($token === '') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "HTTP migrate disabled: MIGRATE_TOKEN is unset.\n";
        exit(1);
    }

    $provided = $_SERVER['HTTP_X_MIGRATE_TOKEN'] ?? ($_GET['token'] ?? '');
    if (!hash_equals($token, (string) $provided)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Forbidden.\n";
        exit(1);
    }
}

$root = dirname(__DIR__);
$migrationsDir = $root . '/app/Infrastructure/Db/Migrations';

try {
    $pdo = Connection::get($config['db']);
    $applied = (new Migrator($pdo, $migrationsDir))->migrate();
    $message = $applied === []
        ? 'No pending migrations.'
        : 'Migrations applied: ' . implode(', ', $applied);
    if ($isCli) {
        echo $message . PHP_EOL;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    $err = 'Migration failed: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $err . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $err . "\n";
    }
    exit(1);
}
