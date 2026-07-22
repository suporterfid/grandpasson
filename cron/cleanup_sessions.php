<?php

declare(strict_types=1);

/**
 * CLI / cron entrypoint: delete expired MySQL sessions.
 * Schedule (cPanel / Docker): every 15 minutes.
 */

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Infrastructure\Cleanup\SessionCleanup;
use GrandpaSSOn\Infrastructure\Db\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';

try {
    $config = ConfigLoader::load();
    $pdo = Connection::get($config['db']);
    $deleted = (new SessionCleanup($pdo))->run();
    $message = 'cleanup_sessions deleted=' . $deleted;
    if ($isCli) {
        echo $message . PHP_EOL;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    $err = 'cleanup_sessions failed: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $err . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $err . "\n";
    }
    exit(1);
}
