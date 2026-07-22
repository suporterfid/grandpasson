<?php

declare(strict_types=1);

/**
 * CLI / cron entrypoint: delete expired (and aged revoked) access tokens.
 * Schedule: daily (or hourly on busy hosts).
 */

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Infrastructure\Cleanup\AccessTokenCleanup;
use GrandpaSSOn\Infrastructure\Db\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

$isCli = PHP_SAPI === 'cli';

try {
    $config = ConfigLoader::load();
    $pdo = Connection::get($config['db']);
    $deleted = (new AccessTokenCleanup($pdo))->run();
    $message = 'cleanup_access_tokens deleted=' . $deleted;
    if ($isCli) {
        echo $message . PHP_EOL;
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message . "\n";
    }
    exit(0);
} catch (Throwable $e) {
    $err = 'cleanup_access_tokens failed: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $err . PHP_EOL);
    } else {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $err . "\n";
    }
    exit(1);
}
