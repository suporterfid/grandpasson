#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Register or update an oauth_clients row (v0 has no admin UI).
 *
 * Usage:
 *   php cron/seed_oauth_client.php \
 *     --client-id=demo-app \
 *     --name="Demo App" \
 *     --redirect-uri=http://localhost:3000/cb \
 *     --secret='change-me' \
 *     [--type=confidential] [--disabled]
 */

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\OAuthClientRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', [
    'client-id:',
    'name:',
    'redirect-uri:',
    'secret::',
    'type::',
    'disabled',
    'help',
]);

if (isset($opts['help']) || !isset($opts['client-id'], $opts['name'], $opts['redirect-uri'])) {
    fwrite(STDERR, <<<'TXT'
Usage:
  php cron/seed_oauth_client.php --client-id=ID --name=NAME --redirect-uri=URI [--secret=SECRET] [--type=confidential|public] [--disabled]

Confidential clients (default) require --secret.

TXT);
    exit(isset($opts['help']) ? 0 : 1);
}

$clientId = (string) $opts['client-id'];
$name = (string) $opts['name'];
$redirectUri = (string) $opts['redirect-uri'];
$type = (string) ($opts['type'] ?? 'confidential');
$secret = array_key_exists('secret', $opts) ? (string) $opts['secret'] : null;
$enabled = !isset($opts['disabled']);

try {
    $config = ConfigLoader::load();
    $pdo = Connection::get($config['db']);
    $repo = new OAuthClientRepository($pdo);
    $client = $repo->upsert($clientId, $name, [$redirectUri], $type, $secret, $enabled);

    echo "Seeded oauth client\n";
    echo "  client_id:     {$client->clientId}\n";
    echo "  name:          {$client->name}\n";
    echo "  type:          {$client->type}\n";
    echo "  enabled:       " . ($client->enabled ? 'yes' : 'no') . "\n";
    echo "  redirect_uris: " . implode(', ', $client->redirectUris) . "\n";
    if ($type === 'confidential') {
        echo "  secret:        (stored as password_hash; plaintext shown only at creation time)\n";
        if ($secret !== null && $secret !== '') {
            echo "  plaintext:     {$secret}\n";
        }
    }
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'seed-oauth-client failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
