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
| `BROKER_BASE_URL` | `https://auth.example.com` (no trailing slash required) |
| `SESSION_COOKIE_SECURE` | `true` behind HTTPS |
| `DB_*` | MySQL credentials from cPanel |
| `ALLOWED_EMAIL_DOMAINS` | Comma-separated; empty outside `APP_ENV=dev` refuses auto-create |
| `MIGRATE_TOKEN` | Leave empty unless you intentionally expose HTTP migrate |
| Provider `*_CLIENT_ID` / `*_CLIENT_SECRET` / redirect URIs | Must match IdP consoles |
| `MS_TENANT_ID` | Directory tenant ID (use `common` only when intentional) |

Confirm PHP extensions: `curl`, `openssl`, `json`, `pdo_mysql`.

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

Prefer CLI cron over HTTP hits.

## 6. HTTPS and cookies

- Enable AutoSSL (or equivalent) and force HTTPS.
- Keep `SESSION_COOKIE_SECURE=true`, cookie name `AUTHSESSID`, `HttpOnly`, `SameSite=Lax`.

## 7. Register apps and IdPs

1. Insert each relying party into `oauth_clients` with **exact** `redirect_uris` (JSON array), `type=confidential`, and a `password_hash()` of the client secret.
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
