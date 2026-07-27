<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\AdminGate;
use GrandpaSSOn\Support\Csrf;
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
            echo '<!doctype html><meta charset="utf-8"><title>Admin disabled</title>';
            echo '<p>Admin HTTP is disabled. Set <code>ADMIN_API_TOKEN</code> to enable.</p>';

            return;
        }

        $csrf = Csrf::token();
        $name = htmlspecialchars((string) ($config['broker']['name'] ?? 'GrandpaSSOn'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $verbOptions = '';
        foreach (AdminCommandRunner::verbs() as $verb) {
            $safe = htmlspecialchars($verb, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $verbOptions .= "      <option value=\"{$safe}\">{$safe}</option>\n";
        }
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{$name} admin</title>
  <style>
    :root { color-scheme: light; --ink:#1a1a1a; --muted:#555; --line:#d8d8d8; --bg:#f7f5f1; --accent:#0b5fff; }
    body { margin:0; font:16px/1.45 "IBM Plex Sans", "Segoe UI", sans-serif; color:var(--ink); background:
      radial-gradient(1200px 600px at 10% -10%, #e8eefc 0%, transparent 55%),
      radial-gradient(900px 500px at 100% 0%, #f3ebe2 0%, transparent 50%),
      var(--bg); }
    main { max-width: 42rem; margin: 0 auto; padding: 2.5rem 1.25rem 4rem; }
    h1 { font: 700 1.75rem/1.2 "IBM Plex Serif", Georgia, serif; margin: 0 0 .35rem; }
    p.lead { color: var(--muted); margin: 0 0 1.5rem; }
    label { display:block; font-size:.85rem; margin: .85rem 0 .25rem; }
    input, select, textarea, button { font: inherit; }
    input, select, textarea { width:100%; box-sizing:border-box; padding:.55rem .65rem; border:1px solid var(--line); border-radius:4px; background:#fff; }
    button { margin-top:1rem; background:var(--accent); color:#fff; border:0; border-radius:4px; padding:.65rem 1rem; cursor:pointer; }
    button:hover { filter:brightness(1.05); }
    #out { margin-top:1.25rem; white-space:pre-wrap; background:#111; color:#d7ffd7; padding:1rem; border-radius:6px; min-height:4rem; font: 13px/1.4 ui-monospace, monospace; }
    .hint { font-size:.8rem; color:var(--muted); }
  </style>
</head>
<body>
<main>
  <h1>{$name} admin</h1>
  <p class="lead">Token-gated management surface mirroring <code>cron/admin.php</code>.</p>
  <form id="admin-form">
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
    <p class="hint">Prefer <code>Authorization: Bearer</code> / <code>X-Admin-Token</code> for API clients. Secrets are shown once in the response.</p>
    <button type="submit">Run</button>
  </form>
  <pre id="out">Ready.</pre>
</main>
<script>
const form = document.getElementById('admin-form');
const out = document.getElementById('out');
form.addEventListener('submit', async (e) => {
  e.preventDefault();
  const fd = new FormData(form);
  const flags = {};
  String(fd.get('flags') || '').split(/\\n/).forEach((line) => {
    const t = line.trim();
    if (!t.startsWith('--')) return;
    const body = t.slice(2);
    const i = body.indexOf('=');
    if (i === -1) flags[body] = '1';
    else flags[body.slice(0, i)] = body.slice(i + 1);
  });
  const args = String(fd.get('args') || '').match(/(?:\"[^\"]*\"|'[^']*'|\\S+)/g) || [];
  const cleaned = args.map((a) => a.replace(/^['\"]|['\"]$/g, ''));
  out.textContent = 'Running…';
  try {
    const res = await fetch('/admin/api', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Admin-Token': String(fd.get('admin_token') || ''),
      },
      body: JSON.stringify({
        csrf: fd.get('csrf'),
        verb: fd.get('verb'),
        args: cleaned,
        flags,
      }),
    });
    const text = await res.text();
    out.textContent = res.status + '\\n' + text;
  } catch (err) {
    out.textContent = String(err);
  }
});
</script>
</body>
</html>
HTML;
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
