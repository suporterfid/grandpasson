# GrandpaSSOn — Shared Hosting Deployment

Step-by-step deploy for cPanel-style PHP + MySQL hosts. Authority: [grandpasson-spec.md](grandpasson-spec.md) §21 and §15.

## 1. Build the release artifact

On a machine with Docker:

```bash
cp .env.example .env   # only needed if missing; build does not require a filled .env
make build
```

This runs `composer install --no-dev` inside `docker/build` and writes `grandpasson-release.zip` containing exactly:

- `public_html/`
- `app/`
- `vendor/` (production deps only)
- `cron/`
- `composer.json`
- `.env.example`

Do **not** upload `.git`, `tests/`, or `docker/`.

## 2. Upload to the host

1. Unpack the zip beside (not inside) the web root if the host allows a sibling layout:

   ```text
   ~/auth.example.com/
     public_html/          ← document root
     app/
     vendor/
     cron/
     composer.json
     .env                  ← create from .env.example (never commit)
   ```

2. If the host only exposes `public_html/`, keep `app/`, `vendor/`, `cron/`, and `.env` **above** `public_html` when possible. The front controller already lives under `public_html/` and loads `../vendor` and `../app`.

3. Upload the bundled `vendor/` — do **not** run Composer on the server.

## 3. Configure environment

```bash
cp .env.example .env
```

Set at least:

| Variable | Notes |
|---|---|
| `APP_ENV` | `prod` (or `dev` only for throwaway demos) |
| `FORCE_HTTPS` | Optional override; when unset, HTTPS is enforced for `APP_ENV=prod` |
| `BROKER_BASE_URL` | `https://auth.example.com` (no trailing slash required) |
| `SESSION_COOKIE_SECURE` | `true` behind HTTPS (forced `true` when HTTPS enforcement is on) |
| `DB_*` | MySQL credentials from cPanel |
| `ALLOWED_EMAIL_DOMAINS` | Comma-separated; empty outside `APP_ENV=dev` refuses auto-create |
| `MIGRATE_TOKEN` | Leave empty unless you intentionally expose HTTP migrate |
| `ADMIN_API_TOKEN` | Leave empty to disable `/admin` UI + `/admin/api`; set a long random secret to enable |
| `ACCESS_TOKEN_TTL_SECONDS` | v1 machine-token TTL (default `900`); clamped to max |
| `ACCESS_TOKEN_TTL_MAX_SECONDS` | Hard max TTL (default `3600`) |
| `AUDIT_RETENTION_DAYS` | Days to keep enriched audit rows (default `90`) |
| `JWT_ACCESS_TOKEN_ENABLED` | `true` to mint optional companion JWTs on `/oauth/token` |
| `JWT_HMAC_SECRET` | HS256 fallback when no RS256 key is active |
| `JWT_KEY_ENCRYPTION_SECRET` | AES-256-GCM key wrapping RS256 private PEMs at rest (required in `prod` before `jwt:key-rotate`) |
| Provider `*_CLIENT_ID` / `*_CLIENT_SECRET` / redirect URIs | Must match IdP consoles |
| `MS_TENANT_ID` | Directory tenant ID (use `common` only when intentional) |

Confirm PHP extensions: `curl`, `openssl`, `json`, `pdo_mysql`.

v1 tenancy / machine-token work is specified in [grandpasson-spec-v1-extension.md](grandpasson-spec-v1-extension.md) (additive to the base spec).

## 4. Run migrations (first deploy)

Shared hosting has no `docker-entrypoint-initdb.d`. From SSH or a one-off CLI cron:

```bash
php cron/migrate.php
```

Re-running is idempotent. Confirm tables are **InnoDB** (not MyISAM).

## 5. Cron jobs (cPanel)

Same scripts as local `make cron` / Docker `cron` profile ([spec §15](grandpasson-spec.md)):

| Schedule | Command |
|---|---|
| Every 15 minutes | `php /home/USER/path/cron/cleanup_sessions.php` |
| Every 5 minutes | `php /home/USER/path/cron/cleanup_auth_codes.php` |
| Hourly | `php /home/USER/path/cron/cleanup_access_tokens.php` |
| Daily (e.g. 03:30) | `php /home/USER/path/cron/cleanup_audit_log.php` (uses `AUDIT_RETENTION_DAYS`) |

Prefer CLI cron over HTTP hits.

Admin mutations (tenants, groups, service clients, token revoke) use:

```bash
php cron/admin.php --help
php cron/admin.php tenant:create acme "Acme Corp"
php cron/admin.php client:create-service "Agent" --scopes=kb:read --aud=workspace/abc
```

Optional HTTP mirror (R12): set `ADMIN_API_TOKEN`, open `/admin`, or `POST /admin/api` with `X-Admin-Token` / `Authorization: Bearer`.

RS256 JWT signing keys (`jwt:key-rotate`): set `JWT_KEY_ENCRYPTION_SECRET` so private PEMs are AES-256-GCM encrypted in MySQL (S2). Required when `APP_ENV=prod`. JWKS still publishes public keys only.

See [client-integration.md](client-integration.md) §5 for machine-token flows. P0 completion checklist: [v1-p0-stop-line.md](v1-p0-stop-line.md).

## 6. HTTPS and cookies

- Enable AutoSSL (or equivalent) at the host/CDN.
- The broker also enforces HTTPS in PHP when `APP_ENV=prod` or `FORCE_HTTPS=true`: cleartext requests get a **301** to `https://…` (honours `X-Forwarded-Proto` for TLS-terminating proxies). Local `APP_ENV=dev` stays HTTP-friendly unless you set `FORCE_HTTPS=true`.
- Optional Apache rules are commented in `public_html/.htaccess` if you prefer host-level redirects in addition to the PHP gate.
- Cookie: `AUTHSESSID`, `HttpOnly`, `SameSite=Lax`, and `Secure` when HTTPS enforcement is on (even if `SESSION_COOKIE_SECURE` was left `false`).

## 7. Register apps and IdPs

1. Seed each relying party (exact redirect URI, confidential secret):

   ```bash
   php cron/seed_oauth_client.php \
     --client-id=my-app \
     --name="My App" \
     --redirect-uri=https://app.example.com/callback \
     --secret='long-random-secret'
   ```

   See [client-integration.md](client-integration.md) for the login + exchange contract.
2. In Google Cloud, Microsoft Entra, and GitHub OAuth Apps, set redirect URIs to:

   - `https://auth.example.com/callback/google`
   - `https://auth.example.com/callback/microsoft`
   - `https://auth.example.com/callback/github`

## 8. Smoke check

- `GET https://auth.example.com/` → health JSON
- `GET https://auth.example.com/session` → `401` without cookie
- Full login against Google (or another configured provider) through a registered confidential client, then `POST /session/exchange`

## Local verification before upload

```bash
make up
make test
make build
make cron          # optional: exercise cleanup schedules locally
```
