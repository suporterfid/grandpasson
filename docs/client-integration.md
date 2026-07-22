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

## 5. Same-host introspection (optional)

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
