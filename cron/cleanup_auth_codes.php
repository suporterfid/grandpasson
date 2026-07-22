<?php

declare(strict_types=1);

/**
 * CLI / cron entrypoint: delete expired or consumed auth codes.
 * Schedule (cPanel / Docker): every 5 minutes.
 */

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Infrastructure\Cleanup\AuthCodeCleanup;
use GrandpaSSOn\Infrastructure\Db\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';

try {
    $config = ConfigLoader::load();
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
