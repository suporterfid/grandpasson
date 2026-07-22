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

JSON response:

```json
{ "id": "…", "email": "…", "display_name": "…", "status": "active" }
```

Create your own app session from that payload. Public / secretless clients are rejected.

v1 additive claims (when tenancy is configured) also include `subject`, `tenant`, `tenants`, `groups`, and `scopes`. See the v1 extension spec §6.2.

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

Rotate secrets with `php cron/admin.php client:rotate-secret <client_id>` (old secret stops working immediately).

## 6. Same-host introspection (optional)

If the browser shares the broker host cookie (`AUTHSESSID`):

```bash
curl -sS https://auth.example.com/session -b 'AUTHSESSID=…'
```

Cross-host apps should prefer exchange, not the cookie.

## 6. Logout

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
