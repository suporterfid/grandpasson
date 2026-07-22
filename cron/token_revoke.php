#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Admin revoke for access tokens (U8). Prefer cron/admin.php once U9 lands.
 *
 * Usage:
 *   php cron/token_revoke.php --token-id=<uuid>
 *   php cron/token_revoke.php --client=<client_id>
 *   php cron/token_revoke.php --subject=<user_id>
 *   php cron/token_revoke.php --token=<gpat_live_...>
 */

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$opts = getopt('', ['token-id::', 'client::', 'subject::', 'token::', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, <<<'TXT'
Usage:
  php cron/token_revoke.php --token-id=UUID
  php cron/token_revoke.php --client=CLIENT_ID
  php cron/token_revoke.php --subject=USER_ID
  php cron/token_revoke.php --token=gpat_live_...

TXT);
    exit(0);
}

$tokenId = isset($opts['token-id']) ? (string) $opts['token-id'] : '';
$clientId = isset($opts['client']) ? (string) $opts['client'] : '';
$subjectId = isset($opts['subject']) ? (string) $opts['subject'] : '';
$plaintext = isset($opts['token']) ? (string) $opts['token'] : '';

$modes = array_filter([$tokenId !== '', $clientId !== '', $subjectId !== '', $plaintext !== '']);
if (count($modes) !== 1) {
    fwrite(STDERR, "Specify exactly one of --token-id, --client, --subject, or --token.\n");
    exit(1);
}

try {
    $config = ConfigLoader::load();
    $pdo = Connection::get($config['db']);
    $tokens = new AccessTokenRepository($pdo);
    $audit = new AuditLogger($pdo);

    if ($tokenId !== '') {
        $count = $tokens->revokeById($tokenId);
        $target = 'token_id:' . $tokenId;
    } elseif ($clientId !== '') {
        $count = $tokens->revokeByClientId($clientId);
        $target = 'client:' . $clientId;
    } elseif ($subjectId !== '') {
        $count = $tokens->revokeBySubjectId($subjectId);
        $target = 'subject:' . $subjectId;
    } else {
        $count = $tokens->revokeByToken($plaintext);
        $target = 'token:hash';
    }

    $audit->record(
        action: 'token.revoke.admin',
        result: AuditLogger::RESULT_SUCCESS,
        actorType: AuditLogger::ACTOR_ADMIN,
        actorId: 'cli',
        target: $target,
    );

    echo "Revoked {$count} token(s) ({$target})\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'token-revoke failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
