<?php

declare(strict_types=1);

/**
 * CLI / cron entrypoint: delete expired or consumed auth codes.
 * Schedule (cPanel / Docker): every 5 minutes.
 * HTTP: only when CRON_TOKEN is set and the request provides a matching
 * X-Cron-Token header or ?token= query param (mirrors cron/migrate.php).
 */

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Infrastructure\Cleanup\AuthCodeCleanup;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\CronHttpGate;

require dirname(__DIR__) . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';

try {
    $config = ConfigLoader::load();

    if (!$isCli) {
        $token = (string) $config['cron_token'];
        $provided = $_SERVER['HTTP_X_CRON_TOKEN'] ?? ($_GET['token'] ?? '');
        if (!CronHttpGate::authorized($token, is_string($provided) ? $provided : null)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo $token === '' ? "HTTP cron disabled: CRON_TOKEN is unset.\n" : "Forbidden.\n";
            exit(1);
        }
    }

    $pdo = Connection::get($config['db']);
    $deleted = (new AuthCodeCleanup($pdo))->run();
    $message = 'cleanup_auth_codes deleted=' . $deleted;
    if ($isCli) {
        echo $message . PHP_EOL;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    $err = 'cleanup_auth_codes failed: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $err . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $err . "\n";
    }
    exit(1);
}
