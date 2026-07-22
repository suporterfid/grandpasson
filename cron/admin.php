#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * GrandpaSSOn admin CLI (v1 §6.7).
 *
 * Usage:
 *   php cron/admin.php <verb> [args…] [--flag=value]
 *
 * Verbs:
 *   tenant:create <slug> <name>
 *   tenant:add-member <tenant> <subject> <role>
 *   group:create <tenant> <slug> [name]
 *   group:add-member <tenant> <group> <subject>
 *   client:create-service <name> --scopes=kb:read[,kb:write] [--aud=workspace/…] [--client-id=…]
 *   client:rotate-secret <client_id>
 *   token:list [--client=…] [--subject=…] [--kind=access|pat]
 *   token:revoke <token_id> | --client=… | --subject=…
 *   pat:create <subject_user_id> --scopes=kb:read[,…] [--label=…] [--aud=…] [--ttl-days=365]
 *   pat:list [--subject=…]
 *   pat:revoke <token_id> | --subject=…
 */

use GrandpaSSOn\Config\ConfigLoader;
use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Db\Connection;

require dirname(__DIR__) . '/vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$argvList = array_values(array_slice($argv, 1));
if ($argvList === [] || in_array($argvList[0], ['-h', '--help', 'help'], true)) {
    fwrite(STDOUT, file_get_contents(__FILE__) !== false
        ? "See header comment in cron/admin.php for verbs.\n"
        : "admin help\n");
    // Print verbs explicitly:
    fwrite(STDOUT, <<<'TXT'
Verbs:
  tenant:create <slug> <name>
  tenant:add-member <tenant> <subject> <role>
  group:create <tenant> <slug> [name]
  group:add-member <tenant> <group> <subject>
  client:create-service <name> --scopes=… [--aud=…] [--client-id=…]
  client:rotate-secret <client_id>
  token:list [--client=…] [--subject=…] [--kind=access|pat]
  token:revoke <token_id> | --client=… | --subject=…
  pat:create <subject_user_id> --scopes=… [--label=…] [--aud=…] [--ttl-days=365]
  pat:list [--subject=…]
  pat:revoke <token_id> | --subject=…

TXT);
    exit(0);
}

$verb = array_shift($argvList);
$flags = [];
$positional = [];
foreach ($argvList as $arg) {
    if (str_starts_with($arg, '--')) {
        $body = substr($arg, 2);
        if (str_contains($body, '=')) {
            [$k, $v] = explode('=', $body, 2);
            $flags[$k] = $v;
        } else {
            $flags[$body] = '1';
        }
    } else {
        $positional[] = $arg;
    }
}

try {
    $config = ConfigLoader::load();
    $pdo = Connection::get($config['db']);
    $runner = AdminCommandRunner::fromPdo($pdo);
    $result = $runner->run((string) $verb, $positional, $flags);

    if (isset($result['client_secret'])) {
        echo "client_id:     {$result['client_id']}\n";
        echo "client_secret: {$result['client_secret']}  (shown once; store securely)\n";
        if (isset($result['scopes'])) {
            echo 'scopes:        ' . implode(' ', $result['scopes']) . "\n";
        }
        if (array_key_exists('aud', $result)) {
            echo 'aud:           ' . ($result['aud'] ?? '(none)') . "\n";
        }
        exit(0);
    }

    if (isset($result['token']) && ($verb === 'pat:create')) {
        echo "token_id:  {$result['token_id']}\n";
        echo "token:     {$result['token']}  (shown once; store securely)\n";
        echo "subject:   {$result['subject_user_id']}\n";
        echo "scope:     {$result['scope']}\n";
        echo 'aud:       ' . ($result['aud'] ?? '(none)') . "\n";
        echo 'label:     ' . ($result['label'] ?? '(none)') . "\n";
        echo "expires:   {$result['expires_at']}\n";
        exit(0);
    }

    if (in_array($verb, ['token:list', 'pat:list'], true) && isset($result['tokens']) && is_array($result['tokens'])) {
        echo "count: {$result['count']}\n";
        foreach ($result['tokens'] as $row) {
            echo sprintf(
                "%s  kind=%s  label=%s  client=%s  subject=%s  scope=%s  aud=%s  exp=%s  last_used=%s\n",
                $row['id'],
                $row['kind'] ?? '-',
                $row['label'] ?? '-',
                $row['client_id'] ?? '-',
                $row['subject_user_id'] ?? '-',
                $row['scope'],
                $row['aud'] ?? '-',
                $row['expires_at'],
                $row['last_used_at'] ?? '-',
            );
        }
        exit(0);
    }

    echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'admin failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
