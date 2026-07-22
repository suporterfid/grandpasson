# GrandpaSSOn — v0 Deployable MVP Plan

> Companion to [`grandpasson-spec.md`](./grandpasson-spec.md). This plan turns the spec into a
> sequenced, verifiable task list for the **first deployable release** (v0).

- **Spec version covered:** 1.3
- **Target output:** `grandpasson-release.zip` that installs and runs on shared PHP/MySQL hosting.
- **Development environment:** Docker Compose (per spec §18) — no local PHP/MySQL required.
- **Token strategy for v0:** **Option A** (spec §17): short-lived broker auth code +
  `POST /session/exchange` for confidential clients; host-only `AUTHSESSID`; no JWT, no full OIDC
  `/token`, no `/userinfo`, no JWKS in v0.

---

## 1. What "v0 deployable MVP" means

v0 is the smallest system that lets a real user click "Sign in", authenticate through **at least one**
external provider, land back in a client app as a provisioned local user, and stay logged in via a
MySQL-backed session — all deployable to shared hosting from a Docker-built zip.

### In scope for v0

- Front controller + router and the **public endpoints**: `GET /login`, `GET /login/{provider}`,
  `GET /callback/{provider}`, `POST /logout`, `GET /session`, `POST /session/exchange`.
- All three providers: **Google (OIDC)**, **Microsoft (OIDC)**, **GitHub (OAuth2 + email fetch)**.
  **Ship bar:** Google live E2E required; Microsoft and GitHub must pass unit/contract tests (live
  creds optional in CI).
- Full MySQL schema (spec §8), applied automatically in Docker and via a migration runner in prod.
- MySQL-backed `SessionHandlerInterface` against the §8 `sessions` table (spec §7 logical metadata
  lives in `data`).
- Broker auth-code mint + confidential-client `POST /session/exchange` (hashed at rest, atomic
  consume, exact `redirect_uri`).
- User provisioning / verified-email auto-link gated by `ALLOWED_EMAIL_DOMAINS` (spec §12).
- CSRF via `state` (client + provider), `nonce` for OIDC, PKCE, ID-token validation (spec §3).
- Audit logging of login success/failure/logout/exchange (spec §8 `audit_events`, §13).
- Cron cleanup scripts + Docker cron service (spec §15) — after Foundation.
- Dockerized dev stack + Docker build pipeline producing the release zip (spec §18).
- Minimal CI: build the artifact and run tests.

### Explicitly deferred (NOT in v0)

| Deferred item | Rationale |
|---|---|
| `/authorize`, full OIDC `/token`/`/userinfo`, `.well-known/*`, JWKS (spec §10 "optional") | Option B. v0 uses `POST /session/exchange` instead of full IdP token endpoint. |
| JWT / opaque token issuance (spec §17 Option B) | Session + auth-code covers server-rendered PHP clients. |
| Admin UI for managing `oauth_clients` | v0 seeds clients via SQL/CLI; UI is post-v0. |
| Provider-email-change review workflow (spec §5, §12) | v0 flags for review by refusing silent switch + audit log; tooling later. |
| Rate limiting beyond a basic guard (spec §13) | Basic per-IP throttle only; richer limits post-v0. |
| Storing provider access tokens / downstream API calls | Not required for identity in v0. |

> Deferred items are still allowed to have interface seams left in place (e.g. `ProviderInterface`), but
> no working implementation is required for v0 sign-off.

---

## 2. Definition of Done (v0 acceptance)

v0 is done when **all** of the following hold:

1. `docker compose up` yields a working broker at `http://localhost:8080` with schema auto-applied.
2. A user can complete the full login flow end-to-end against **at least Google**, and the other two
   providers pass their unit/contract tests (live creds optional in CI).
3. Sessions survive across requests using MySQL only (no filesystem session state).
4. `POST /logout` deletes the session row and clears the cookie.
5. Cron scripts delete only expired/consumed rows and run identically in the Docker `cron` service.
6. Every login success, login failure, and logout writes an `audit_events` row (tokens/secrets redacted).
7. `make build` produces `grandpasson-release.zip` containing `public_html/`, `app/`, `vendor/`,
   `cron/`, config templates — and no `.git`, tests, or Docker files.
8. A documented deploy path (spec §21 checklist) plus a migration runner lets the zip stand up on a
   fresh MySQL database without SSH/Composer on the server.
9. CI is green: builds the artifact and runs the test suite.
10. `README.md` has tagline, Docker quickstart, and deploy pointer; `.env.example` lists every required var.

---

## 3. Milestones

| Milestone | Goal | Tasks |
|---|---|---|
| **M0 — Foundation** | Repo scaffolding, Docker dev stack, schema boots | T0, T1, T11 |
| **M1 — Core plumbing** | Router, config, DB connection, migration runner, MySQL sessions | T-config, T-db, T-runner, T-router, T2 |
| **M2 — Providers** | Normalized identity from all three providers | T-iface, T3, T4, T5 |
| **M3 — Flows** | Login → callback → provision → mint code → exchange → logout/session | T6, T-provision, T7, T-exchange, T8, T-session-ep |
| **M4 — Ops & hardening** | Cron cleanup, audit logging, remaining security helpers | T9, T10, T-security |
| **M5 — Ship** | Build pipeline, CI, deploy docs, release zip | T12, T-tests, T-ci, T-deploydoc |

Recommended order (extends spec §20):
`T0 → T1 → T11 → T-config → T-db → T-runner → T-router → T2 → T-iface → (T3 ∥ T4 ∥ T5) → T6 → T-provision → T7 → T-exchange → T8 → T-session-ep → T9 → T10 → T-security → T12 → T-tests → T-ci → T-deploydoc`

---

## 4. Task list

Each task lists files, dependencies, and a single verifiable acceptance criterion. Tasks prefixed `T#`
map to spec §20; tasks prefixed `T-` are additions this MVP plan makes explicit (glue the spec implies
but does not itemize).

### M0 — Foundation

- **T0 — Project bootstrap**
  - Files: `LICENSE` (exists), `README.md`, `composer.json`, `.env.example`, `.gitignore`
  - Dep: none
  - Accept: `composer.json` declares the four required packages (spec §16); `.env.example` lists every
    var referenced by config; README has tagline + Docker quickstart. `.gitignore` excludes `.env`,
    `/dist`, `grandpasson-release.zip` (but **not** `/vendor` — vendor is committed/bundled).

- **T1 — Migrations (schema)**
  - Files: `app/Infrastructure/Db/Migrations/001..006_*.sql`, `docker/mysql/init/001..006_*.sql`
  - Dep: none
  - Accept: the six tables from spec §8 exist as InnoDB; `docker/mysql/init` copies auto-apply on first
    MySQL boot. The two migration directories are kept identical (single source, copied/symlinked).

- **T-runner — Migration runner for production**
  - Files: `cron/migrate.php` (or `app/Infrastructure/Db/Migrator.php` + a CLI entrypoint; HTTP only
    if gated by `MIGRATE_TOKEN` and fail-closed when unset)
  - Dep: T1, T-db
  - Accept: on shared hosting (no `docker-entrypoint-initdb.d`), running the migrator against an empty
    DB creates all six tables idempotently and records applied migrations. Prefer CLI/cPanel cron;
    if HTTP is exposed, require `MIGRATE_TOKEN`.

- **T11 — Docker dev stack**
  - Files: `docker-compose.yml`, `docker/nginx/{Dockerfile,default.conf}`, `docker/php/{Dockerfile,php.ini}`,
    `docker/mysql/init/*`
  - Dep: T1
  - Accept: `docker compose up` serves the front controller at `:8080` (default `web` Apache+PHP
    service with `pdo_mysql`), MySQL comes up seeded with six InnoDB tables. Optional
    `docker compose --profile split` runs nginx+php-fpm; `make tools` starts phpMyAdmin on `:8081`.

### M1 — Core plumbing

- **T-config — Config loader**
  - Files: `app/Config/config.php`, consumes `.env`
  - Dep: T0
  - Accept: returns typed config for broker base URL, cookie settings, DB DSN, `ALLOWED_EMAIL_DOMAINS`,
    `MS_TENANT_ID`, `MIGRATE_TOKEN`, and per-provider client_id/secret/redirect_uri/scopes; missing
    required env fails fast with a clear error.

- **T-db — Database connection**
  - Files: `app/Infrastructure/Db/Connection.php`
  - Dep: T-config
  - Accept: returns a configured PDO (utf8mb4, exceptions on, prepared statements) to the Docker MySQL;
    single shared instance per request.

- **T-router — Front controller + router**
  - Files: `public_html/index.php`, `public_html/.htaccess`, `app/Http/Router.php`
  - Dep: T-config
  - Accept: routes the public endpoints (including `POST /session/exchange`) to controllers; `.htaccess`
    rewrites to `index.php` and denies direct access to `/app`; unknown route returns 404.

- **T2 — MySQL session handler**
  - Files: `app/Infrastructure/Session/MysqlSessionHandler.php`
  - Dep: T1, T-db
  - Accept: implements `SessionHandlerInterface` against the `sessions` table; session persists across
    requests via MySQL only; writes only when data changes; cookie is `HttpOnly; Secure; SameSite=Lax`,
    name `AUTHSESSID`; session ID regenerated after login. Identical code path in Docker and prod.

### M2 — Providers

- **T-iface — Provider interface + normalized identity**
  - Files: `app/Infrastructure/Providers/ProviderInterface.php`, a `NormalizedIdentity` DTO
  - Dep: none
  - Accept: defines `getAuthorizationUrl(state, nonce, pkce)` and `handleCallback(request):
    NormalizedIdentity` where identity exposes `provider, subject, email, email_verified, name,
    avatar_url, username`.

- **T3 — Google provider (OIDC)**
  - Files: `app/Infrastructure/Providers/GoogleProvider.php`
  - Dep: T-iface
  - Accept: returns normalized identity with `sub, email, email_verified, name`; validates ID token
    (`iss, aud, exp, signature, nonce`) before trusting claims.

- **T4 — Microsoft provider (OIDC)**
  - Files: `app/Infrastructure/Providers/MicrosoftProvider.php`
  - Dep: T-iface
  - Accept: returns normalized identity with `sub, email, email_verified, name`; validates ID token as
    above; uses `MS_TENANT_ID` discovery (not `/common` unless explicitly configured); never treats raw
    UPN as verified email for auto-link.

- **T5 — GitHub provider (OAuth2)**
  - Files: `app/Infrastructure/Providers/GithubProvider.php`
  - Dep: T-iface
  - Accept: exchanges code for token; fetches primary **verified** email via GitHub API when absent from
    the profile; returns normalized identity with `subject(id), username(login), email, email_verified`.

### M3 — Flows

- **T6 — Login controller**
  - Files: `app/Http/Controllers/LoginController.php`
  - Dep: T3, T4, T5, T-router
  - Accept: `GET /login` renders provider chooser; `GET /login/{provider}` validates `client_id` +
    `redirect_uri` (exact match against `oauth_clients`), rejects when `oauth_clients.enabled=0`
    (and audits), stores provider `state`/`nonce`/PKCE **and** client-supplied `state` + `return_to`
    in session, and 302s to the provider with correct params. Failure matrix: invalid client/redirect
    → 400; disabled client → 403 + audit.

- **T7 — Callback controller**
  - Files: `app/Http/Controllers/CallbackController.php`
  - Dep: T6, T2, T-provision
  - Accept: verifies provider `state`, exchanges code, validates ID token/nonce (OIDC) or fetches
    userinfo (GitHub), resolves/creates the local user, creates a MySQL session, mints a single-use
    auth code (**store `code_hash` only**), and redirects to the client's registered `redirect_uri`
    with raw `code` **and** the original client `state`. On failure: never redirect with a code;
    audit + safe error page.

- **T-exchange — Session exchange (broker auth-code redemption)**
  - Files: `app/Http/Controllers/SessionExchangeController.php`
  - Dep: T7, T-db
  - Accept: `POST /session/exchange` requires `code`, `client_id`, `client_secret`, `redirect_uri`;
    rejects `type=public` and missing `client_secret_hash`; exact `redirect_uri` match against the
    auth-code row; consumes via one transactional update (`consumed=0` AND unexpired); returns
    `{id,email,display_name,status}`; audits failures; concurrent double-redeem yields exactly one success.

- **T-provision — User provisioning / identity linking**
  - Files: `app/Domain/{User,LinkedIdentity,OAuthClient}.php`, a `UserProvisioner` service
  - Dep: T-db
  - Accept: finds user by `(provider, provider_subject)`; else auto-links by **verified** email within
    `ALLOWED_EMAIL_DOMAINS` (empty allowlist refuses auto-create outside `APP_ENV=dev`); else creates
    a new user; refuses unverified email / raw UPN; syncs `display_name`/`avatar_url` on login; on
    provider-email change, flags for review (no silent primary-email switch) + audit event.

- **T8 — Logout controller**
  - Files: `app/Http/Controllers/LogoutController.php`
  - Dep: T2
  - Accept: `POST /logout` deletes the MySQL session row and clears the `AUTHSESSID` cookie; CSRF-guarded.

- **T-session-ep — Session endpoint**
  - Files: `app/Http/Controllers/SessionController.php`
  - Dep: T2, T7
  - Accept: `GET /session` returns the current authenticated user (id, email, display_name, status) as
    JSON for cookie-authenticated requests, and 401 otherwise — broker/same-host introspection only;
    cross-host clients use `T-exchange`.

### M4 — Ops & hardening

- **T9 — Cron cleanup**
  - Files: `cron/cleanup_sessions.php`, `cron/cleanup_auth_codes.php`, `docker/` cron wiring
  - Dep: T1, T-db, T11
  - Accept: deletes only rows past `expires_at` (and consumed auth codes); runs identically via CLI on
    shared hosting and in the Docker `cron` service on the spec §15 schedule.

- **T10 — Audit logging**
  - Files: touches `app/Http/Controllers/*.php` + an `AuditLogger` helper
  - Dep: T7, T8, T-exchange
  - Accept: every login success, login failure, logout, and exchange success/failure writes an
    `audit_events` row with `event_type`, `provider`, hashed IP, timestamp; no tokens/secrets recorded.

- **T-security — Security helpers (cross-cutting leftovers)**
  - Files: `app/Support/Csrf.php`, `app/Support/Http.php`, rate-limit helper
  - Dep: T6, T7, T-exchange
  - Accept: shared helpers for PKCE/state storage, HTTP utilities, and basic per-IP throttle on
    `/login`, `/callback`, and `/session/exchange`; secrets read only from env outside webroot.
    Core checks (exact redirect, disabled client/user, client `state` echo, confidential exchange)
    are already required in T6/T7/T-exchange acceptance — do not defer them here.

### M5 — Ship

- **T12 — Docker build pipeline**
  - Files: `docker-compose.build.yml`, `docker/build/Dockerfile.build`, `docker/build/build.sh`, `Makefile`
  - Dep: T11
  - Accept: `make build` runs `composer install --no-dev --optimize-autoloader` in the build container and
    emits `grandpasson-release.zip` containing exactly the deployable set (public_html, app, vendor, cron,
    composer.json, .env.example) and excluding `.git`, tests, and Docker files.

- **T-ci — CI workflow**
  - Files: `.github/workflows/ci.yml`
  - Dep: T12, T-tests
  - Accept: on push/PR, CI runs the test suite against the Docker stack and runs `make build`; on tag,
    it uploads `grandpasson-release.zip` as a release artifact. No production secrets in images.

- **T-tests — Test suite**
  - Files: `phpunit.xml`, `tests/**`
  - Dep: grows with each milestone (write alongside, not at the end)
  - Accept: unit tests for provider identity normalization, session handler round-trip, provisioning
    rules (verified vs unverified link), redirect-URI validation, disabled `oauth_clients` rejection,
    and cron deletion boundaries.

- **T-deploydoc — Deployment guide**
  - Files: `docs/deployment.md`, README pointer
  - Dep: T12, T-runner
  - Accept: step-by-step shared-hosting deploy following spec §21, including running the migration runner
    on first deploy and creating the two cPanel cron jobs; provider setup notes (Google/MS/GitHub redirect
    URIs).

---

## 5. Gaps this plan closes beyond spec §20

The spec's task list is provider- and Docker-complete but assumes some glue. This plan makes the
following v0-critical additions explicit so nothing blocks a real deploy:

1. **Migration runner for production** (`T-runner`) — Docker auto-applies schema via
   `docker-entrypoint-initdb.d`, but shared hosting has no equivalent. Without a runner, a fresh prod DB
   has no tables.
2. **Config loader, DB connection, router** (`T-config`, `T-db`, `T-router`) — named in the folder
   structure (spec §9) but absent from §20; every controller depends on them.
3. **Provider interface + normalized identity DTO** (`T-iface`) — the shared contract the three
   providers implement and the callback consumes.
4. **User provisioning service** (`T-provision`) — spec §12 policy needs a home; the callback should not
   embed linking rules directly.
5. **`/session` endpoint controller** (`T-session-ep`) — listed as a public endpoint (spec §10) and named
   in §9 but missing from §20.
6. **`POST /session/exchange`** (`T-exchange`) — closes the cross-host auth-code loop without Option B.
7. **Security helpers + test suite + CI + deploy doc** (`T-security`, `T-tests`, `T-ci`, `T-deploydoc`) —
   required by the Definition of Done and the deployment checklist.

---

## 6. Risks & mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| Shared host lacks a required PHP extension (`curl`, `openssl`, `pdo_mysql`) | Broker won't run | Deploy checklist verifies extensions; config loader fails fast listing the missing one. |
| Host defaults tables to MyISAM | Session contention, broken FKs | Migrations pin `ENGINE=InnoDB`; deploy checklist confirms engine. |
| No Composer on server | Can't resolve deps | `vendor/` committed and bundled into the zip (spec §16); never resolved in prod. |
| Provider ID-token validation implemented loosely | Auth bypass | Use `firebase/php-jwt` + discovery JWKS; unit-test iss/aud/exp/nonce failures. |
| Session cookie not `Secure` behind a proxy | Session theft | Force HTTPS + secure cookie via config; document AutoSSL step. |
| Two copies of migration SQL drift | Docker and prod schemas diverge | Single source; build/CI diffs the two migration directories. |
| Auth-code double redeem | Session fixation / account confusion | Atomic consume predicate; concurrent exchange test in T-exchange. |

---

## 7. Suggested first PRs (execution slices)

1. **Foundation PR** — T0, T1, T11: `docker compose up` boots with schema; empty front controller responds.
2. **Plumbing PR** — T-config, T-db, T-runner, T-router, T2: routed requests, migrator, MySQL sessions.
3. **Providers PR** — T-iface, T3, T4, T5 with unit tests.
4. **Flow PR** — T6, T-provision, T7, T-exchange, T8, T-session-ep: full end-to-end login against Google.
5. **Ops PR** — T9, T10, T-security.
6. **Ship PR** — T12, T-tests round-out, T-ci, T-deploydoc: green CI + release zip.
