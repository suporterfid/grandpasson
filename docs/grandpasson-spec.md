# GrandpaSSOn — Minimalist PHP + MySQL SSO Broker

> "SSO that runs where your grandpa's cPanel still lives."

Version: 1.2
License: MIT
Target runtime environment: Shared PHP/MySQL hosting (cPanel-style, no SSH/root, no persistent daemons, no Redis/queues)
Target development environment: Dockerized (Docker Compose), fully reproducible regardless of host OS
Purpose: Central authentication broker for internal apps, federating identity from Google, Microsoft, and GitHub.
Audience: Human developers and AI coding agents implementing this system incrementally.

---

## 0. Project Identity

```yaml
project:
  name: GrandpaSSOn
  tagline: "SSO that runs where your grandpa's cPanel still lives."
  license: MIT
  repo_owner: suporterfid
  language: PHP
  database: MySQL (InnoDB)
  category: minimalist self-hosted SSO broker
  dev_environment: docker-compose
  build_output: shared_hosting_deployable_zip
```

### Why MIT

MIT was chosen over Apache 2.0 or AGPL because GrandpaSSOn is meant to be forked, self-hosted, and embedded into other projects (including on cheap shared hosting) with the least possible friction. MIT is short, OSI-approved, GitHub-recognized, and does not impose network-use copyleft obligations the way AGPL would, which matters for a tool people will run on constrained shared hosting rather than as a hosted service.

### LICENSE file (MIT)

```text
MIT License

Copyright (c) 2026 GrandpaSSOn Contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

---

## 1. Scope and Assumptions

- One central PHP auth "broker" application: **GrandpaSSOn**.
- Multiple internal apps (PHP or otherwise) trust the broker for login.
- Upstream identity providers: Google, Microsoft (Entra ID), GitHub.
- No SAML, no enterprise IdP product (Keycloak/authentik/ZITADEL) — this is a PHP-only, self-built minimalist broker.
- **Production runtime constraints** (shared hosting):
  - No SSH/root access assumed.
  - No persistent background workers or daemons.
  - No guaranteed Redis/Memcached.
  - Composer CLI may not be available on the server — dependencies are built locally/in Docker and uploaded.
  - Cron only available via cPanel-style scheduled jobs (PHP CLI or HTTP hit).
  - Runtime is stateless per HTTP request (PHP-FPM or mod_php).
  - MySQL (InnoDB) is the single source of truth for all persistence, including sessions.
- **Development environment constraint (new in this version):**
  - The entire local development, testing, and build/publish pipeline must be **Dockerized**, so any contributor or AI coding agent can spin up an identical environment with a single command, independent of host OS, PHP version, or MySQL version installed locally.

---

## 2. Architecture Overview

```yaml
architecture:
  runtime: php-fpm_or_mod_php_shared_hosting
  persistence: mysql_only
  session_backend: mysql_innodb_table
  background_jobs: cpanel_cron_polling_only
  process_model: stateless_per_request
  dependency_management: composer_vendor_committed
  external_services: google_oidc, microsoft_oidc, github_oauth2
  dev_and_build_environment: docker_compose_based
```

### Components

- **GrandpaSSOn Broker** — central PHP app exposing `/login`, `/callback/{provider}`, `/logout`, `/session`. Redirects users to the provider, validates the callback, maps external identity to a local user, creates the canonical local session.
- **App Clients** — internal apps redirect unauthenticated users to the broker and trust the broker's session or issued code/token.
- **Identity Store** — MySQL database with users, linked identities, sessions, auth codes, registered clients, and audit logs.
- **Dockerized Dev/Build Stack** — local PHP-FPM + Nginx (or Apache) + MySQL + phpMyAdmin (optional) containers for development, plus a separate build container/stage that produces a shared-hosting-ready deployment artifact.

### High-Level Flow

```text
Browser -> App -> GrandpaSSOn /login
GrandpaSSOn -> Provider /authorize
Provider -> GrandpaSSOn /callback?code&state
GrandpaSSOn -> Provider /token
GrandpaSSOn -> Provider /userinfo (or validate id_token)
GrandpaSSOn -> MySQL (link/provision user)
GrandpaSSOn -> create MySQL-backed session
GrandpaSSOn -> App callback with broker code
App -> GrandpaSSOn /session or /token
App -> authenticated user session
```

---

## 3. Protocol Rules

- Use OpenID Connect (OIDC) whenever the provider supports it (Google, Microsoft).
- Use OAuth 2.0 Authorization Code flow for all providers (GitHub does not support full OIDC, only OAuth2).
- Require `state` on every authorization request; verify it on callback (CSRF protection).
- Use `nonce` for OIDC providers.
- Prefer PKCE even for confidential server-side clients as defense in depth.
- Validate ID tokens: check `iss`, `aud`, `exp`, signature, before trusting any claim.
- Never trust a raw email from a provider unless it is marked verified or fetched from a trusted authenticated API call.

---

## 4. Provider Configuration

```yaml
providers:
  google:
    protocol: oidc
    scopes: [openid, profile, email]
    required_claims: [sub, email, email_verified, name]
    discovery: https://accounts.google.com/.well-known/openid-configuration

  microsoft:
    protocol: oidc
    scopes: [openid, profile, email]
    required_claims: [sub, email_or_upn, name]
    discovery: https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration

  github:
    protocol: oauth2
    scopes: [read:user, user:email]
    required_claims: [id, login]
    extra_fetch: primary_verified_email
    authorize_url: https://github.com/login/oauth/authorize
    token_url: https://github.com/login/oauth/access_token
    api_base_url: https://api.github.com
```

GitHub notes: email may not be present in the initial profile payload and often requires an additional authenticated call to the GitHub API to fetch the user's primary verified email.

---

## 5. Local Identity Model

```yaml
user:
  id: uuid
  primary_email: string
  email_verified: boolean
  display_name: string
  avatar_url: string|null
  status: active|disabled
  created_at: datetime
  updated_at: datetime

linked_identity:
  id: uuid
  user_id: uuid
  provider: google|microsoft|github
  provider_subject: string
  provider_email: string|null
  provider_username: string|null
  raw_claims_json: json
  linked_at: datetime
  last_login_at: datetime
```

Rules:
- Canonical identity is the internal `user.id`, never the provider's subject.
- Unique constraint on `(provider, provider_subject)`.
- Auto-link accounts by verified email only; never auto-link on unverified email.
- If the provider email changes, treat as requiring review rather than silently switching primary email.

---

## 6. Client App Model

```yaml
oauth_client:
  client_id: string
  client_secret_hash: string|null
  name: string
  redirect_uris: string[]
  type: confidential|public
  allowed_scopes: [openid, profile, email, roles]
  enabled: boolean
```

Rules:
- Exact redirect URI match required (no partial/prefix matching).
- Each internal app gets a distinct `client_id`.
- Secrets stored hashed at rest.
- `return_to` / redirect target must be validated against the registered redirect URIs — never blindly echoed back.

---

## 7. Session Model (MySQL-backed)

```yaml
session:
  id: uuid
  user_id: uuid
  client_id: string|null
  created_at: datetime
  expires_at: datetime
  last_seen_at: datetime
  ip_hash: string|null
  user_agent_hash: string|null
  amr: [social_login]
```

Rules:
- Sessions are stored server-side in MySQL (InnoDB), never relying on local filesystem session paths, which are unreliable across shared-hosting process isolation and restarts.
- InnoDB is required over MyISAM because InnoDB provides row-level locking, avoiding contention when many concurrent requests read/write session rows.
- Session cookie flags: `HttpOnly`, `Secure`, `SameSite=Lax` (or stricter).
- Regenerate session ID after successful login.
- Only write session rows when data actually changes, to minimize write load on shared MySQL.
- Logout destroys the MySQL session row and clears the cookie.

---

## 8. Database Schema (MySQL / InnoDB)

```sql
CREATE TABLE users (
  id CHAR(36) NOT NULL PRIMARY KEY,
  primary_email VARCHAR(255) NOT NULL UNIQUE,
  email_verified TINYINT(1) NOT NULL DEFAULT 0,
  display_name VARCHAR(255) NOT NULL,
  avatar_url VARCHAR(500) NULL,
  status ENUM('active','disabled') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL
) ENGINE=InnoDB;

CREATE TABLE linked_identities (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  provider ENUM('google','microsoft','github') NOT NULL,
  provider_subject VARCHAR(255) NOT NULL,
  provider_email VARCHAR(255) NULL,
  provider_username VARCHAR(255) NULL,
  raw_claims_json TEXT NULL,
  linked_at DATETIME NOT NULL,
  last_login_at DATETIME NOT NULL,
  UNIQUE KEY uniq_provider_subject (provider, provider_subject),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE oauth_clients (
  client_id VARCHAR(100) NOT NULL PRIMARY KEY,
  client_secret_hash VARCHAR(255) NULL,
  name VARCHAR(255) NOT NULL,
  redirect_uris TEXT NOT NULL,
  type ENUM('confidential','public') NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

CREATE TABLE sessions (
  id CHAR(64) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NULL,
  data MEDIUMBLOB NOT NULL,
  last_access INT UNSIGNED NOT NULL,
  expires_at INT UNSIGNED NOT NULL
) ENGINE=InnoDB;

CREATE TABLE auth_codes (
  code CHAR(64) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  client_id VARCHAR(100) NOT NULL,
  redirect_uri VARCHAR(500) NOT NULL,
  expires_at INT UNSIGNED NOT NULL,
  consumed TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE audit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  user_id CHAR(36) NULL,
  event_type VARCHAR(100) NOT NULL,
  provider VARCHAR(50) NULL,
  ip_hash VARCHAR(64) NULL,
  created_at DATETIME NOT NULL
) ENGINE=InnoDB;
```

---

## 9. Folder Structure (AI-Agent Ready, Docker-Included)

```text
grandpasson/
/public_html/                 <- webroot (only this is web-exposed)
  index.php                   <- front controller, routes requests
  .htaccess                   <- rewrite rules, deny direct access to /app
  assets/

/app/                         <- outside webroot if host allows, else /public_html/app with .htaccess deny
  Config/
    config.php                <- loads .env-style values
  Http/
    Router.php
    Controllers/
      LoginController.php
      CallbackController.php
      LogoutController.php
      SessionController.php
  Domain/
    User.php
    LinkedIdentity.php
    OAuthClient.php
  Infrastructure/
    Db/
      Connection.php
      Migrations/
        001_create_users.sql
        002_create_linked_identities.sql
        003_create_oauth_clients.sql
        004_create_sessions.sql
        005_create_auth_codes.sql
        006_create_audit_events.sql
    Session/
      MysqlSessionHandler.php
    Providers/
      GoogleProvider.php
      MicrosoftProvider.php
      GithubProvider.php
      ProviderInterface.php
  Support/
    Csrf.php
    Jwt.php
    Http.php

/cron/
  cleanup_sessions.php
  cleanup_auth_codes.php

/docker/                       <- NEW: dockerized dev/build environment
  php/
    Dockerfile                 <- PHP-FPM dev image (extensions: pdo_mysql, curl, openssl, json)
    php.ini
  nginx/
    Dockerfile
    default.conf               <- proxies to php-fpm, serves /public_html
  mysql/
    init/
      001_create_users.sql
      002_create_linked_identities.sql
      003_create_oauth_clients.sql
      004_create_sessions.sql
      005_create_auth_codes.sql
      006_create_audit_events.sql
  build/
    Dockerfile.build           <- composer install --no-dev, produces /dist artifact
    build.sh                   <- packages /dist into shared-hosting-ready zip

docker-compose.yml              <- dev stack: nginx + php-fpm + mysql + (optional) phpmyadmin
docker-compose.build.yml        <- one-shot build service producing the deployable artifact
Makefile                        <- make up / make down / make build / make migrate / make test

/vendor/                        <- committed, not built on the shared-hosting server itself
.env.example
composer.json
LICENSE                         <- MIT
README.md                       <- project intro, tagline, quickstart, docker instructions
```

---

## 10. Minimal Endpoints

```yaml
public_endpoints:
  - GET /login
  - GET /login/{provider}
  - GET /callback/{provider}
  - POST /logout
  - GET /session

optional_oidc_like_endpoints:
  - GET /authorize
  - POST /token
  - GET /userinfo
  - GET /.well-known/openid-configuration
  - GET /.well-known/jwks.json
```

Minimal mode: internal apps rely only on broker session exchange via `/login` and `/callback`. The optional OIDC-like endpoints are for when GrandpaSSOn itself needs to act as a stable identity provider for many client apps.

---

## 11. Login Flow Contract

```yaml
request:
  GET /login/{provider}:
    params:
      client_id: required
      redirect_uri: required
      response_type: code
      scope: openid profile email
      state: required
      nonce: required_for_oidc

callback_processing:
  verify_state: true
  exchange_code: true
  validate_id_token_if_present: true
  fetch_userinfo_if_needed: true
  resolve_or_create_local_user: true
  create_session: true
  issue_local_code_or_token: true
```

Validation checklist on callback:
- Verify `state` matches the one issued at `/login`.
- Exchange `code` for tokens over server-to-server HTTPS call.
- If ID token present, validate issuer, audience, expiration, signature, nonce.
- Fetch `userinfo` (or GitHub API) if claims are incomplete (e.g., missing email).
- Resolve existing `linked_identity` or create new user + linked identity.
- Create session row in MySQL, set session cookie.
- Redirect back to the requesting app with an auth code or session confirmation.

---

## 12. Provisioning Policy

```yaml
provisioning:
  auto_create_user: true
  auto_link_verified_email: true
  require_verified_email: true
  update_profile_on_login: true
  sync_fields:
    - display_name
    - avatar_url
    - primary_email
```

Rules:
- Create a new user on first login if no existing link is found.
- Link to an existing user only by verified email.
- On subsequent logins, sync display name and avatar automatically.
- Manual review required if provider email changes or is unverified.

---

## 13. Security Requirements

- Store all secrets in environment variables outside the web root; never commit them to the repo or place them in `public_html`.
- Enforce CSRF protection via `state` parameter on every OAuth flow.
- Enforce HTTPS everywhere, including all redirect URIs (most shared hosts provide free SSL via AutoSSL).
- Validate ID token signature and claims for all OIDC providers.
- Avoid storing provider access tokens unless downstream API access is genuinely required; if stored, encrypt at rest and rotate.
- Rate-limit `/login` and `/callback` endpoints to reduce abuse.
- Log authentication events to `audit_events`, redacting tokens and secrets.
- Support local account disable/block even when upstream provider login succeeds.
- Docker dev secrets (`.env` used inside containers) must never be the same values as production secrets, and must never be committed.

---

## 14. Session Handling Details (Shared Hosting)

```yaml
session_strategy:
  handler: custom_mysql_session_handler
  interface: SessionHandlerInterface
  write_frequency: only_on_change
  cleanup: cron_based_ttl_sweep
  cookie:
    name: AUTHSESSID
    secure: true
    httponly: true
    samesite: Lax
```

- Implement PHP's native `SessionHandlerInterface`, backed by the `sessions` table.
- This avoids reliance on local filesystem `session.save_path`, which can be inconsistent or isolated per-process on shared hosts.
- Only persist a write when session data changes, to reduce contention and I/O.
- The same session handler code path must work unmodified inside Docker (dev) and on shared hosting (prod) — no environment-specific session logic branches.

---

## 15. Cron-Based Cleanup (Replaces Daemons)

```yaml
cron_jobs:
  - path: /cron/cleanup_sessions.php
    schedule: "*/15 * * * *"
    action: delete_expired_sessions
  - path: /cron/cleanup_auth_codes.php
    schedule: "*/5 * * * *"
    action: delete_expired_or_consumed_codes
```

Production: set these up as scheduled jobs in the hosting control panel (cPanel-style), invoking PHP via CLI or a protected HTTP endpoint.

Development: the Docker Compose stack must include an optional lightweight cron container (e.g., `docker/cron` service using a simple crond image) that runs the same two scripts on the same schedule, so cron behavior can be tested locally before deployment.

---

## 16. Dependency / Composer Policy

```yaml
dependencies_policy:
  composer_cli_on_shared_host: not_assumed_available
  composer_cli_in_docker: always_available
  strategy: build_in_docker_commit_vendor
  required_packages:
    - league/oauth2-client
    - league/oauth2-google
    - league/oauth2-github
    - firebase/php-jwt
  avoid:
    - anything_requiring_pecl_extensions_not_default_enabled
    - redis_client_libraries
    - queue_worker_packages
```

Since SSH/Composer CLI access cannot be assumed on shared hosting, all dependency resolution happens inside the Docker build container (`docker/build/Dockerfile.build`), and the resulting `vendor/` directory is committed or bundled into the deployment artifact — never resolved on the production server itself.

---

## 17. Token Strategy Options

**Option A — Session-only broker** (recommended default for minimalism):
- Best for classic server-rendered PHP apps on the same or related domains.
- App trusts the broker session/cookie directly or via a short-lived auth code.
- Simplest to implement and maintain on shared hosting.

**Option B — Broker issues JWT/opaque tokens**:
- Better for APIs, decoupled frontends, or non-PHP client apps.
- Requires `/token`, `/userinfo`, key management, and expiration handling.
- Adds complexity; only adopt if multiple independent systems need to validate identity without a shared session.

---

## 18. Dockerized Development & Build/Publish Environment (New Requirement)

### 18.1 Goals

- A new contributor or AI coding agent must be able to run `docker compose up` (or `make up`) and get a fully working GrandpaSSOn instance (PHP + MySQL + web server) with zero manual local PHP/MySQL installation.
- The same Docker tooling must also produce the final shared-hosting deployment artifact (a zip with `public_html/`, `app/`, `vendor/`, and config templates), so "it works in Docker" and "it works on shared hosting" are verified by the same pipeline.
- Docker is for **dev, test, and build/publish only** — it is never a deployment target for production, since the target production environment is shared PHP/MySQL hosting without container support.

### 18.2 Dev stack (docker-compose.yml)

```yaml
services:
  nginx:
    build: ./docker/nginx
    ports:
      - "8080:80"
    volumes:
      - ./public_html:/var/www/html/public_html
      - ./app:/var/www/html/app
    depends_on:
      - php

  php:
    build: ./docker/php
    volumes:
      - ./public_html:/var/www/html/public_html
      - ./app:/var/www/html/app
      - ./vendor:/var/www/html/vendor
    environment:
      - APP_ENV=dev
    env_file:
      - .env

  mysql:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: grandpasson
      MYSQL_ROOT_PASSWORD: devrootpass
      MYSQL_USER: grandpasson
      MYSQL_PASSWORD: devpass
    volumes:
      - ./docker/mysql/init:/docker-entrypoint-initdb.d
      - dbdata:/var/lib/mysql
    ports:
      - "3306:3306"

  cron:
    build: ./docker/php
    command: ["crond", "-f", "-l", "2"]
    volumes:
      - ./cron:/var/www/html/cron
    env_file:
      - .env
    depends_on:
      - mysql

  phpmyadmin:
    image: phpmyadmin:latest
    environment:
      PMA_HOST: mysql
    ports:
      - "8081:80"
    depends_on:
      - mysql

volumes:
  dbdata:
```

Rules:
- `nginx` + `php` (PHP-FPM) mirror the shared-hosting Apache/PHP-FPM behavior as closely as possible.
- `mysql` container auto-runs the migration SQL files from `docker/mysql/init` on first boot, so schema is always in sync with Section 8.
- `cron` container runs the same cleanup scripts on the same schedule as production, for local verification.
- `phpmyadmin` is optional/dev-only, never shipped to production.

### 18.3 Build/publish stack (docker-compose.build.yml)

```yaml
services:
  build:
    build:
      context: .
      dockerfile: ./docker/build/Dockerfile.build
    volumes:
      - ./:/workspace
    command: ["/workspace/docker/build/build.sh"]
```

`docker/build/Dockerfile.build` (conceptual):

```dockerfile
FROM composer:2 AS composer
FROM php:8.2-cli
COPY --from=composer /usr/bin/composer /usr/bin/composer
WORKDIR /workspace
```

`docker/build/build.sh` (conceptual):

```bash
#!/bin/sh
set -e
composer install --no-dev --optimize-autoloader
rm -rf dist && mkdir dist
cp -r public_html app vendor cron composer.json .env.example dist/
cd dist && zip -r ../grandpasson-release.zip . 
```

Rules:
- The build container installs production-only Composer dependencies (`--no-dev`), never dev/test dependencies, in the shipped artifact.
- Output is a single `grandpasson-release.zip` containing exactly what should be uploaded to shared hosting (no `.git`, no test files, no Docker files).
- This build step must be runnable both locally (`make build`) and in CI (GitHub Actions), guaranteeing the release artifact is always reproducible from source.

### 18.4 Makefile targets (convenience layer)

```makefile
up:
	docker compose up -d

down:
	docker compose down

migrate:
	docker compose exec mysql sh -c 'echo "Migrations auto-applied via docker-entrypoint-initdb.d on first boot"'

test:
	docker compose exec php php vendor/bin/phpunit

build:
	docker compose -f docker-compose.build.yml run --rm build
```

### 18.5 CI/CD guidance

- A GitHub Actions workflow should run `make build` on every tag/release to produce `grandpasson-release.zip` as a downloadable release artifact.
- The same workflow should optionally run `make test` against the Docker Compose dev stack (spun up as a CI service) before allowing a build/release.
- No production secrets are ever baked into Docker images; secrets are injected via `.env` at runtime (dev) or uploaded separately (shared hosting).

---

## 19. Reference Configuration

```yaml
broker:
  name: GrandpaSSOn
  base_url: https://auth.example.com
  session_cookie:
    name: AUTHSESSID
    secure: true
    httponly: true
    samesite: Lax
    ttl_minutes: 480

providers:
  google:
    client_id: env(GOOGLE_CLIENT_ID)
    client_secret: env(GOOGLE_CLIENT_SECRET)
    redirect_uri: https://auth.example.com/callback/google
    discovery: https://accounts.google.com/.well-known/openid-configuration
    scopes: [openid, profile, email]

  microsoft:
    client_id: env(MS_CLIENT_ID)
    client_secret: env(MS_CLIENT_SECRET)
    redirect_uri: https://auth.example.com/callback/microsoft
    discovery: https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration
    scopes: [openid, profile, email]

  github:
    client_id: env(GITHUB_CLIENT_ID)
    client_secret: env(GITHUB_CLIENT_SECRET)
    redirect_uri: https://auth.example.com/callback/github
    authorize_url: https://github.com/login/oauth/authorize
    token_url: https://github.com/login/oauth/access_token
    api_base_url: https://api.github.com
    scopes: [read:user, user:email]

clients:
  - client_id: app_admin
    redirect_uris:
      - https://admin.example.com/auth/callback
  - client_id: app_portal
    redirect_uris:
      - https://portal.example.com/auth/callback
```

---

## 20. AI Coding Agent Task Breakdown

Each task below is scoped to specific files with a single acceptance criterion, formatted for independent, verifiable implementation by an AI coding agent.

```yaml
tasks:
  - id: T0_project_bootstrap
    files: [LICENSE, README.md, composer.json, .env.example]
    output: project_named_grandpasson_mit_licensed_readme_has_tagline_and_docker_quickstart

  - id: T1_migrations
    files: [Infrastructure/Db/Migrations/*.sql, docker/mysql/init/*.sql]
    output: schema_matches_spec_section_8_and_auto_applies_in_docker

  - id: T2_session_handler
    files: [Infrastructure/Session/MysqlSessionHandler.php]
    depends_on: [T1_migrations]
    acceptance: session_persists_across_requests_via_mysql_only_in_docker_and_shared_hosting

  - id: T3_provider_google
    files: [Infrastructure/Providers/GoogleProvider.php]
    acceptance: returns_normalized_identity_with_sub_email_email_verified_name

  - id: T4_provider_microsoft
    files: [Infrastructure/Providers/MicrosoftProvider.php]
    acceptance: returns_normalized_identity_with_sub_email_name

  - id: T5_provider_github
    files: [Infrastructure/Providers/GithubProvider.php]
    acceptance: fetches_primary_verified_email_if_missing_from_profile

  - id: T6_login_controller
    files: [Http/Controllers/LoginController.php]
    depends_on: [T3_provider_google, T4_provider_microsoft, T5_provider_github]
    acceptance: redirects_to_correct_provider_with_state_and_nonce

  - id: T7_callback_controller
    files: [Http/Controllers/CallbackController.php]
    depends_on: [T6_login_controller, T2_session_handler]
    acceptance: validates_state_exchanges_code_links_or_creates_user_creates_session

  - id: T8_logout_controller
    files: [Http/Controllers/LogoutController.php]
    acceptance: destroys_mysql_session_row_and_cookie

  - id: T9_cron_cleanup
    files: [cron/cleanup_sessions.php, cron/cleanup_auth_codes.php]
    depends_on: [T1_migrations]
    acceptance: deletes_rows_past_expires_at_only_and_runs_identically_in_docker_cron_service

  - id: T10_audit_logging
    files: [Http/Controllers/*.php]
    acceptance: every_login_success_failure_logout_writes_audit_events_row

  - id: T11_docker_dev_stack
    files: [docker-compose.yml, docker/nginx/*, docker/php/*, docker/mysql/init/*]
    output: single_command_docker_compose_up_produces_working_local_instance

  - id: T12_docker_build_pipeline
    files: [docker-compose.build.yml, docker/build/Dockerfile.build, docker/build/build.sh, Makefile]
    depends_on: [T11_docker_dev_stack]
    output: make_build_produces_grandpasson_release_zip_ready_for_shared_hosting_upload
```

Recommended execution order: T0 -> T1 -> T11 -> T2 -> (T3, T4, T5 in parallel) -> T6 -> T7 -> T8 -> T9 -> T10 -> T12.

---

## 21. Deployment Checklist (Shared Hosting)

- [ ] Build the release artifact via `make build` (Docker) rather than manually assembling files.
- [ ] Upload the `vendor/` folder (bundled inside the release zip) — do not rely on Composer CLI running on the server.
- [ ] Place `.env` and non-public PHP files outside `public_html` if the host allows it; otherwise `.htaccess`-deny direct access.
- [ ] Confirm PHP extensions enabled: `curl`, `openssl`, `json`, `pdo_mysql`.
- [ ] Create the two cPanel cron jobs listed in Section 15 (same scripts validated locally via the Docker `cron` service).
- [ ] Enforce HTTPS-only cookies and force HTTPS redirects (use free AutoSSL if available).
- [ ] Confirm all tables use the InnoDB engine, not the host's legacy MyISAM default.
- [ ] Register each internal app as an `oauth_client` row with exact redirect URIs.
- [ ] Verify OAuth app credentials (client ID/secret) are correctly configured in Google Cloud Console, Microsoft Entra, and GitHub OAuth Apps, matching the redirect URIs above.
- [ ] Add `LICENSE` (MIT) and `README.md` with project name, tagline, quickstart, and Docker dev instructions before publishing publicly.

---

## 22. Recommendation Summary

For **GrandpaSSOn**, a minimalist PHP + MySQL SSO broker on shared hosting:
- Use OIDC for Google and Microsoft; OAuth2 + API fetch for GitHub.
- Store all state (users, identities, sessions, codes, audit) in MySQL/InnoDB only.
- Avoid Redis, queues, and daemons entirely — use cron polling for cleanup (mirrored locally via a Docker `cron` service).
- Default to session-only trust between broker and apps; only add token issuance (`/token`, `/userinfo`) if multiple independent, non-session-sharing systems require it.
- Develop, test, and build exclusively inside Docker Compose so any contributor or AI agent gets an identical environment; ship dependencies via a committed/bundled `vendor/` directory produced by the Docker build stage, never resolved on the production server.
- License under MIT to keep adoption friction near zero for self-hosters and forkers.
