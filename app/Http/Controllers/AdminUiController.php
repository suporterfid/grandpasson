<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\AdminGate;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Html;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

/**
 * Minimal admin HTML UI (R12) — token-gated forms mirroring cron/admin.php verbs.
 */
final class AdminUiController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function index(array $config, array $params = []): void
    {
        if (!RateLimitGate::allow('admin_ui')) {
            http_response_code(429);
            header('Content-Type: text/plain; charset=utf-8');
            echo "rate_limited\n";

            return;
        }

        if (!AdminGate::isConfigured($config)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo Html::pageStart($config, 'Admin disabled');
            echo '<div class="prose">';
            echo '<h1>Admin disabled</h1>';
            echo '<p>Admin HTTP is disabled. Set <code>ADMIN_API_TOKEN</code> to enable.</p>';
            echo '</div>';
            echo Html::pageEnd();

            return;
        }

        $csrf = Csrf::token();
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $verbOptions = '';
        foreach (AdminCommandRunner::verbs() as $verb) {
            $safe = Html::e($verb);
            $verbOptions .= "      <option value=\"{$safe}\">{$safe}</option>\n";
        }
        $apiUrl = Html::e(Html::basePath($config) . '/admin/api');
        $scriptSrc = Html::e(Html::asset($config, 'admin.js'));
        $safeName = Html::e($name);

        header('Content-Type: text/html; charset=utf-8');
        echo Html::pageStart($config, $name . ' admin');
        echo <<<HTML
<div class="prose">
  <h1>{$safeName} admin</h1>
  <p class="lead">Token-gated management surface mirroring <code>cron/admin.php</code>.</p>
  <form id="admin-form" data-api-url="{$apiUrl}">
    <input type="hidden" name="csrf" value="{$csrf}">
    <label for="admin_token">Admin API token</label>
    <input id="admin_token" name="admin_token" type="password" autocomplete="off" required>
    <label for="verb">Verb</label>
    <select id="verb" name="verb">
{$verbOptions}    </select>
    <label for="args">Positional args (space-separated)</label>
    <input id="args" name="args" placeholder="acme &quot;Acme Corp&quot;">
    <label for="flags">Flags (one --key=value per line)</label>
    <textarea id="flags" name="flags" rows="4" placeholder="--scopes=kb:read&#10;--aud=workspace/abc"></textarea>
    <p class="text-small">Prefer <code>Authorization: Bearer</code> / <code>X-Admin-Token</code> for API clients. Secrets are shown once in the response.</p>
    <button class="btn btn--primary" type="submit">Run</button>
  </form>
  <pre id="out">Ready.</pre>
</div>
<script src="{$scriptSrc}" defer></script>
HTML;
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function api(array $config, array $params = []): void
    {
        if (!RateLimitGate::allow('admin_api')) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        if (!AdminGate::isConfigured($config)) {
            Http::json(403, ['error' => 'admin_disabled', 'message' => 'Set ADMIN_API_TOKEN to enable admin HTTP']);

            return;
        }

        if (!AdminGate::authorize($config)) {
            Http::json(401, ['error' => 'unauthorized']);

            return;
        }

        $body = Http::readBody();
        $csrf = isset($body['csrf']) ? (string) $body['csrf'] : null;
        // Browser UI sends CSRF; pure API clients may omit when using header token only.
        if ($csrf !== null && $csrf !== '' && !Csrf::validate($csrf)) {
            Http::json(403, ['error' => 'invalid_csrf']);

            return;
        }

        $verb = (string) ($body['verb'] ?? '');
        $args = $body['args'] ?? [];
        $flags = $body['flags'] ?? [];
        if ($verb === '' || !is_array($args) || !is_array($flags)) {
            Http::json(400, ['error' => 'invalid_request', 'message' => 'Require verb, args[], flags{}']);

            return;
        }

        $argList = [];
        foreach ($args as $a) {
            if (!is_string($a) && !is_int($a) && !is_float($a)) {
                Http::json(400, ['error' => 'invalid_request', 'message' => 'args must be strings']);

                return;
            }
            $argList[] = (string) $a;
        }
        $flagMap = [];
        foreach ($flags as $k => $v) {
            if (!is_string($k)) {
                Http::json(400, ['error' => 'invalid_request', 'message' => 'flag keys must be strings']);

                return;
            }
            $flagMap[$k] = is_scalar($v) || $v === null ? (string) $v : '';
        }

        try {
            $pdo = Connection::get($config['db']);
            $result = AdminCommandRunner::fromPdo($pdo, $config)->run($verb, $argList, $flagMap);
            Http::json(200, $result);
        } catch (\InvalidArgumentException $e) {
            Http::json(400, ['error' => 'invalid_argument', 'message' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Http::json(500, ['error' => 'admin_failed', 'message' => $e->getMessage()]);
        }
    }
}
