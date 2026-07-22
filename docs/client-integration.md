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

Create your own app session from that payload. Public / secretless clients are rejected. Treat unknown keys as ignorable. See the v1 extension spec §6.2 for claim authority. When the user has no tenant membership, `tenant` is `null` and `tenants` / `groups` are empty arrays.

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

`/oauth/token` and `/oauth/introspect` use **DB-backed** per-IP rate limits (shared-hosting friendly; no Redis). Other auth routes keep the file-backed gate.

Rotate secrets with `php cron/admin.php client:rotate-secret <client_id>` (old secret stops working immediately).

### Personal Access Tokens (user-issued)

For an agent acting **on behalf of a user** (R10), mint a long-lived opaque PAT via admin CLI (hashed at rest; plaintext shown once):

```bash
php cron/admin.php pat:create <user_uuid> \
  --scopes=kb:read \
  --label="Notes agent" \
  --aud=workspace/abc123 \
  --ttl-days=365
```

List / revoke: `pat:list [--subject=…]`, `pat:revoke <token_id>|--subject=…`.

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
