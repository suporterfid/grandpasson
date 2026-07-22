# Client integration (v0)

How an internal app uses GrandpaSSOn without Option B (`/token` / JWKS). Authority: spec §10–§11 and `POST /session/exchange`.

## 1. Register the app

v0 has no admin UI. Seed a **confidential** client (exact redirect URI):

```bash
php cron/seed_oauth_client.php \
  --client-id=demo-app \
  --name="Demo App" \
  --redirect-uri=http://localhost:3000/callback \
  --secret='choose-a-long-secret'
```

Or `make seed-client CLIENT_ID=demo-app REDIRECT_URI=http://localhost:3000/callback SECRET='…'`.

## 2. Send the user to the broker

Browser redirect (example for Google):

```text
GET https://auth.example.com/login/google
  ?client_id=demo-app
  &redirect_uri=http://localhost:3000/callback
  &state=OPAQUE_CSRF_STATE
```

`state` is required and must be echoed back unchanged. Optional: `return_to` (stored in the broker session for your own post-login UX; not used by exchange).

## 3. Handle the broker callback

On success the broker redirects to your **exact** `redirect_uri`:

```text
http://localhost:3000/callback?code=BROKER_CODE&state=OPAQUE_CSRF_STATE
```

Verify `state` matches what you issued. The `code` is single-use, short-lived, and bound to your client + redirect URI.

## 4. Redeem the code (server-side)

```bash
curl -sS -X POST https://auth.example.com/session/exchange \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'code=BROKER_CODE' \
  -d 'client_id=demo-app' \
  -d 'client_secret=choose-a-long-secret' \
  -d 'redirect_uri=http://localhost:3000/callback'
```

JSON response (v0 fields plus v1 tenancy when configured):

```json
{
  "id": "42",
  "email": "user@example.com",
  "display_name": "Example User",
  "status": "active",
  "subject": {
    "id": "42",
    "email": "user@example.com",
    "name": "Example User",
    "idp": "google"
  },
  "tenant": { "id": "…", "slug": "acme", "role": "admin" },
  "tenants": [{ "id": "…", "slug": "acme", "role": "admin" }],
  "groups": ["editors"],
  "scopes": ["openid", "profile", "email", "tenant:read"]
}
```

Create your own app session from that payload. Public / secretless clients are rejected at `/session/exchange` — use the authorization_code + PKCE grant on `/oauth/token` instead (below). Treat unknown keys as ignorable. See the v1 extension spec §6.2 for claim authority. When the user has no tenant membership, `tenant` is `null` and `tenants` / `groups` are empty arrays.

### Public clients (authorization_code + PKCE)

Public RPs (`--type=public` when seeding) must send PKCE on login and redeem the broker code at `POST /oauth/token`:

```text
GET /login/google?client_id=spa-app&redirect_uri=…&state=…&code_challenge=…&code_challenge_method=S256
```

```bash
curl -sS -X POST https://auth.example.com/oauth/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=authorization_code' \
  -d 'client_id=spa-app' \
  -d 'code=BROKER_CODE' \
  -d 'redirect_uri=https://spa.example/cb' \
  -d 'code_verifier=…'
```

Response includes `access_token`, `expires_in`, and `sub`. Confidential clients may still use `/session/exchange` (optional `code_verifier` if PKCE was used at login).

## 5. Service clients (v1 machine tokens)

For agents / MCP / TaskConnect use a **service client** (separate from RP `oauth_clients`):

```bash
php cron/admin.php client:create-service "Notes Agent" \
  --scopes=kb:read \
  --aud=workspace/abc123
```

The CLI prints `client_secret` **once**. Store it in the agent's secret store; the broker keeps only a password hash.

Issue a short-lived opaque token:

```bash
curl -sS -X POST https://auth.example.com/oauth/token \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d 'grant_type=client_credentials' \
  -d 'client_id=svc_…' \
  -d 'client_secret=…' \
  -d 'scope=kb:read'
```

Validate with `POST /oauth/introspect` and revoke with `POST /oauth/revoke` (or `php cron/admin.php token:revoke …`).

`/oauth/token` and `/oauth/introspect` use **DB-backed** per-IP rate limits (shared-hosting friendly; no Redis). Login, IdP callback, and reader-login routes use the same DB counters with a stricter policy: **15 attempts / 5 minutes**, then an **IP lockout of 15 minutes**. Other non-login auth routes keep the file-backed gate.

Optional JWT fast-path (R15/R16): set `JWT_ACCESS_TOKEN_ENABLED=true`. Prefer rotatable RS256 keys via `php cron/admin.php jwt:key-rotate` and publish public keys at `GET /.well-known/jwks.json`. If no RSA keys exist yet, `JWT_HMAC_SECRET` enables HS256. Token responses include a companion `jwt` field; the opaque `access_token` remains the revocation authority via introspect.

Rotate secrets with `php cron/admin.php client:rotate-secret <client_id>` (old secret stops working immediately).

### Personal Access Tokens (user-issued)

Authenticated subjects manage their own PATs (R10) via the broker session cookie (`AUTHSESSID`):

```bash
# List (also returns a csrf token for mutations)
curl -sS https://auth.example.com/me/pats -b 'AUTHSESSID=…'

# Create (plaintext token shown once; hashed at rest)
curl -sS -X POST https://auth.example.com/me/pats -b 'AUTHSESSID=…' \
  -H 'Content-Type: application/json' \
  -d '{"csrf":"…","scopes":"kb:read","label":"Notes agent","aud":"workspace/abc123","ttl_days":365}'

# Revoke
curl -sS -X POST https://auth.example.com/me/pats/<token_id>/revoke -b 'AUTHSESSID=…' \
  -H 'Content-Type: application/json' \
  -d '{"csrf":"…"}'
```

Admin break-glass CLI remains available:

```bash
php cron/admin.php pat:create <user_uuid> \
  --scopes=kb:read \
  --label="Notes agent" \
  --aud=workspace/abc123 \
  --ttl-days=365
```

List / revoke (admin): `pat:list [--subject=…]`, `pat:revoke <token_id>|--subject=…`.

Introspection (`POST /oauth/introspect` with a service client) returns `token_use: "pat"`, `sub` = user id, `client_id` null, and updates `last_used_at` when active.

## 6. Same-host introspection (optional)

If the browser shares the broker host cookie (`AUTHSESSID`):

```bash
curl -sS https://auth.example.com/session -b 'AUTHSESSID=…'
```

Cross-host apps should prefer exchange, not the cookie.

## 7. Logout

```bash
curl -sS -X POST https://auth.example.com/logout \
  -H 'Content-Type: application/x-www-form-urlencoded' \
  -d "csrf=TOKEN_FROM_SESSION"
```

## 8. Gated publishing (reader sessions)

Reader auth is isolated from editor login (`GPSREADER` cookie ≠ `AUTHSESSID`).

- Chooser: `GET /site/{site_id}/login` (HTML links to google/microsoft/github). Optional `?provider=google` skips the chooser.
- Provider start: `GET /site/{site_id}/login/{provider}` → IdP → shared `/callback/{provider}` → reader cookie with `publish:read` only.
- Session check: `GET /site/{site_id}/session`
  - **Browser** (`Accept: text/html`): anonymous gated sites **302** to `/site/{id}/login`.
  - **API / XHR** (`Accept: application/json` or non-HTML): **401 JSON** with a `login` path hint — the relying party owns any in-app redirect UX.
- Private sites enforce tenant membership at login time; reader sessions never unlock editor `/session`.

## Local smoke path

```bash
make up
make migrate
make seed-client CLIENT_ID=demo-app \
  REDIRECT_URI=http://localhost:3000/callback \
  SECRET='dev-secret'
# Configure GOOGLE_* (or another provider) in .env, then open:
# http://localhost:8080/login/google?client_id=demo-app&redirect_uri=http://localhost:3000/callback&state=test
```
