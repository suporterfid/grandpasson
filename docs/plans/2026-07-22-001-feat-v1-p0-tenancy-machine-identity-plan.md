---
title: "feat: GrandpaSSOn v1 P0 — tenancy claims + machine identity"
date: 2026-07-22
type: feat
origin: docs/grandpasson-spec-v1-extension.md
companion_spec: docs/grandpasson-spec.md
execution: code
artifact_readiness: implementation-ready
---

# feat: GrandpaSSOn v1 P0 — tenancy claims + machine identity

## Goal Capsule

- **Objective:** Deliver the v1 extension **P0 stop line** (§11): tenancy model; extended `session/exchange` claims; service-client client-credentials tokens (opaque); introspection + revocation; enriched audit; CLI management; cron GC/retention — without breaking v0 tests or the release zip.
- **Authority:** `docs/grandpasson-spec-v1-extension.md` (additive). Base `docs/grandpasson-spec.md` wins on existing v0 behavior.
- **Stop when:** All P0 acceptance criteria pass (`make test`, `make build`); no secrets in zip; then **stop and request review** before P1/P2.
- **Out of scope:** PATs (R10), auth-code+PKCE for public clients (R11), admin HTTP UI (R12), DB-backed rate limits on oauth endpoints beyond existing file throttle unless pulled into R13 as P1, reader sessions (R14), JWT access tokens (R15–R16).

## Problem Frame

v0 brokers federated login and `POST /session/exchange` for relying parties. Downstream apps (notes platform, TaskConnect, MCP/agents) need (1) tenant/role/group claims in one exchange, (2) short-lived revocable machine tokens instead of static secrets, (3) later gated publishing. Centralizing this in the broker avoids inconsistent app-local auth.

## Key Technical Decisions (from extension §10 defaults)

| ID | Decision |
|----|----------|
| KTD1 | Keep explicit `tenant_members.role` (`owner`/`admin`/`member`) **and** groups (Q1). |
| KTD2 | Opaque tokens only for P0; JWT deferred to P2 (Q2). |
| KTD3 | Session-exchange remains the RP user path; auth-code+PKCE stays P1 (Q3). |
| KTD4 | Access-token TTL default **15 min**, hard max **60 min**, both env-configurable (Q4). |
| KTD5 | `workspace_id` is opaque `aud` only; notes app is canonical registry (Q6). |
| KTD6 | Do not break v0 `oauth_clients` (RP confidential clients). Add a distinct **service client** model (new table or typed rows — prefer **new `service_clients` table** to avoid conflating redirect_uris with machine clients). |
| KTD7 | Extend audit: add richer `audit_log` (extension §8) **in addition to** keeping v0 `audit_events` writes working; migrate call sites gradually or dual-write from a unified logger facade. Prefer **new table + facade** so v0 columns stay intact. |
| KTD8 | Token prefix `gpat_live_`; store **SHA-256** of full token only; client secrets **password_hash** (bcrypt/argon2id via `password_hash`). |

## Current codebase anchors

| Area | Today | v1 impact |
|------|-------|-----------|
| Exchange | `app/Http/Controllers/SessionExchangeController.php` returns `{id,email,display_name,status}` | Additive claims envelope (§6.2) while preserving existing top-level fields **or** nesting under `session` — **preserve flat v0 fields and add sibling keys** (`subject`, `tenant`, `tenants`, `groups`, `scopes`) for backward compatibility with current clients. |
| Clients | `oauth_clients` + `cron/seed_oauth_client.php` | Keep for RP; new service-client seed/CLI |
| Tokens | Broker auth codes only (`auth_codes`) | New `access_tokens` (opaque) table |
| Audit | `audit_events` (user_id, event_type, provider, ip_hash) | New richer `audit_log` + facade |
| Cron | `cleanup_sessions`, `cleanup_auth_codes` | Add token GC + audit retention |
| Router | `app/Http/Router.php` + `public_html/index.php` | Register `/oauth/token`, `/oauth/introspect`, `/oauth/revoke` |
| Rate limit | File-backed `RateLimitGate` | Reuse on new oauth routes in P0; DB counters = P1 R13 |

## Data model (P0 migrations)

Idempotent `CREATE TABLE IF NOT EXISTS` under `app/Infrastructure/Db/Migrations/` **and** mirrored `docker/mysql/init/` (existing `make check-migrations` contract).

Suggested tables (names adjustable; keep InnoDB):

1. `tenants` — `id`, `slug` UNIQUE, `name`, timestamps, `status`
2. `tenant_members` — `(tenant_id, user_id)` PK/unique, `role` ENUM
3. `groups` — `id`, `tenant_id`, `slug` UNIQUE per tenant, `name`
4. `group_members` — `(group_id, user_id)`
5. `service_clients` — `client_id`, `client_secret_hash`, `name`, `allowed_scopes` (JSON/text), `default_audience` NULL, `enabled`, timestamps
6. `access_tokens` — `id`, `token_hash` UNIQUE, `client_id`, `subject_user_id` NULL, `scope`, `aud`, `tenant_id` NULL, `expires_at`, `revoked_at` NULL, `created_at`, `last_used_at` NULL
7. `audit_log` — per extension §8 (`actor_type`, `actor_id`, `action`, `target`, `client_id`, `ip_hash`, `user_agent`, `result`, `created_at`)

No destructive changes to v0 tables.

## Implementation units (P0 order)

### U1 — Land extension spec + env knobs
- Copy/commit `docs/grandpasson-spec-v1-extension.md` (this plan’s origin).
- Extend `.env.example` / `ConfigLoader`: `ACCESS_TOKEN_TTL_SECONDS` (900), `ACCESS_TOKEN_TTL_MAX_SECONDS` (3600), `AUDIT_RETENTION_DAYS`, token GC schedule notes.
- **Test:** config loads new keys with defaults; missing required v0 keys still fail fast.

### U2 — R1 Tenancy schema + repositories
- Migrations + repositories for tenants/members/groups.
- **Tests:** migrate empty DB; unique slug; member role constraints; group membership round-trip.

### U3 — R7 Audit facade + `audit_log`
- New table + `AuditLogger` (or `SecurityAuditLogger`) writing §8 shape; keep v0 `audit_events` compatible (dual-write or adapter).
- **Tests:** token failure writes `result=failure`; no secrets in row payload.

### U4 — R6 / S1–S5 Security primitives
- Helpers: opaque token mint (`gpat_live_` + random), SHA-256 hash lookup, `hash_equals` verify, secret hashing.
- Build/CI test: scan `app/` and release zip for credential-like literals (S1).
- **Tests:** constant-time path used; plaintext token never stored; S1 scanner fails on planted fixture string in a unit test sandbox.

### U5 — R2 Extended `session/exchange` claims
- Resolve active tenant (default: sole membership, else first by stable order / `TODO(spec)` if multi-tenant — safest default: sole member → that tenant; else lowest `slug` until explicit “active tenant” exists).
- Load groups for active tenant; build response additions.
- **Tests:** admin+editors fixture → claims; no membership → `tenant=null`, `tenants=[]`, still 200; v0 fields still present.

### U6 — R3 Service clients + `POST /oauth/token`
- Table + CLI seed; controller; router; scope allowlist + audience optional.
- **Tests:** happy path ≤ TTL; `invalid_scope`; `invalid_client` + audit; no client_id existence leak in message.

### U7 — R4 `POST /oauth/introspect`
- Client-authenticated introspection; inactive shape exactly `{active:false}`; update `last_used_at` on active.
- **Tests:** revoked/expired/unknown → inactive only; valid → scope/aud/exp.

### U8 — R5 `POST /oauth/revoke` + CLI revoke
- Idempotent 200; admin revoke by token_id / client_id / subject_id.
- **Tests:** revoke then introspect inactive; double revoke 200.

### U9 — R8 Management CLI suite
- Scripts under `cron/` (ships in zip), mirroring extension §6.7 verbs (`tenant:*`, `group:*`, `client:*`, `token:*`).
- Prefer one `cron/admin.php` with subcommands **or** small scripts consistent with `seed_oauth_client.php` / `migrate.php`.
- **Tests:** CLI create tenant + add member visible to exchange claims test; rotate secret invalidates old secret.

### U10 — R9 Cron maintenance
- `cleanup_access_tokens.php` (expired and optionally old revoked); `cleanup_audit_log.php` (retention).
- Wire Docker cron crontab + Makefile targets.
- **Tests:** deletion boundaries (only expired/eligible rows).

### U11 — Wire + regression gate
- Router/`index.php` routes; RateLimitGate on new endpoints; README + deployment + client-integration doc updates for machine tokens.
- **Verify:** full `make test`; `make build`; v0 PHPUnit suite unchanged green.

## Sequencing

```
U1 → U2 → U3 → U4 → U5 → U6 → U7 → U8 → U9 → U10 → U11
         ↘________↗ (U3/U4 can parallel after U2)
```

Do not start P1 issues until P0 stop line reviewed.

## Risks

| Risk | Mitigation |
|------|------------|
| Breaking RP clients that parse exchange strictly | Additive JSON keys only; keep v0 fields |
| Confusing RP `oauth_clients` with service clients | Separate table + CLI verbs |
| Timing leaks on client auth | Always run password_verify against dummy hash when client missing |
| Shared-hosting crypto | `password_hash` / `hash` / `hash_equals` / `random_bytes` only; no `exec` |
| Migration tree drift | `make check-migrations` in CI |

## Deferred (track as separate issues, do not implement in P0)

- **P1:** R10 PAT, R11 auth-code+PKCE, R12 admin HTTP UI, R13 DB rate limits on oauth
- **P2:** R14 reader sessions, R15 JWT, R16 key rotation
