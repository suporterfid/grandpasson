# GrandpaSSOn — v1 Extension Spec

**Tenancy claims · Machine identity · Gated publishing**

- **Status:** Draft for implementation
- **Audience:** Cursor coding agents working in `suporterfid/grandpasson`
- **Relationship to base spec:** This document is **additive** to `docs/grandpasson-spec.md` (the product/protocol authority). Where this document and the base spec conflict on existing v0 behavior, **the base spec wins**. This document only adds new capabilities and constrains how they are built.
- **Downstream consumers:** the Obsidian-like notes app (relying party), `suporterfid/taskconnect` (service client), and AI agents / MCP servers consuming published knowledge.

---

## 0. How Cursor agents should use this document

1. **Do not break v0.** The existing broker flow (federated login → `POST /session/exchange`, relying-party clients, cron cleanup) must keep passing its current tests.
2. **Work top-down by priority.** Implement **P0** requirements first, in order. Do not start a P1 until every P0 has passing tests and acceptance criteria met.
3. **Definition of Done for any requirement:**
   - Code lives under the existing layout (`app/`, `public_html/`, `cron/`, `tests/`).
   - A DB migration exists and is **idempotent** (safe to re-run).
   - Unit/integration tests cover the acceptance criteria (`make test` green).
   - No secret, key, or credential is hardcoded — everything from `.env` (see §7).
   - `make build` still produces a working `grandpasson-release.zip`.
4. **Stop conditions are explicit** (§11). When the P0 set is complete and green, stop and request review. Do not scope-creep into P1/P2.
5. **When a requirement is ambiguous,** consult §10 Open Questions. If still unresolved, leave a `TODO(spec): <question>` and continue with the safest default named in this doc — do not invent auth behavior.

---

## 1. Problem statement

GrandpaSSOn v0 is a minimal SSO **broker**: it federates identity (Google, Microsoft, GitHub) and hands a relying party a session via `POST /session/exchange`. That is sufficient for a single app with a single user pool.

The wider `suporterfid` ecosystem now needs three things v0 does not provide:

1. **Tenancy.** A notes/knowledge platform is multi-tenant with many workspaces per tenant. Relying parties need to know *which organization a user belongs to and with what role* — without each app re-deriving that.
2. **Machine identity.** AI agents, an MCP server, a retrieval API, and TaskConnect callbacks must authenticate **non-interactively** (no browser). Today there is no secure, scoped, revocable way to do this. A static shared secret is not acceptable.
3. **Gated publishing.** A workspace published as a public site sometimes needs to be **reader-authenticated** (private docs), which is a different, lighter flow than the editor login.

Cost of not solving: every downstream app reinvents authorization inconsistently, and machine access gets bolted on with static secrets — the exact class of finding (open rules, hardcoded credentials, fake auth) we are actively remediating elsewhere. Centralizing this in the broker is the fix.

---

## 2. Goals

- **G1 — One identity + coarse-authorization plane.** Relying parties receive stable subject identity **plus** tenant membership, group membership, and tenant role, in one exchange. (Fine-grained per-workspace permission stays in the relying party — see §3 boundary.)
- **G2 — Secure non-interactive access.** Agents and services obtain **short-lived, scoped, revocable** tokens via a standards-based flow, with server-side revocation.
- **G3 — Optional reader-auth for published sites**, isolated from editor sessions.
- **G4 — Runs unchanged on cPanel-style shared hosting** (PHP 8.2+, MySQL 8.0+, no daemons, `exec` disabled, deploy-by-zip).
- **G5 — Auditable.** Every token issuance, admin change, and auth failure is logged.

**Measurable outcomes**

- A relying party can authorize a request using only claims from GrandpaSSOn, with **zero** direct IdP calls of its own.
- A service (TaskConnect / MCP) can call a protected endpoint using a client-credentials token that can be **revoked in < 1 request** and expires automatically.
- Zero secrets in the repo or in `grandpasson-release.zip` (verified by a test).

---

## 3. Non-goals (explicit — prevents scope creep)

- **N1 — GrandpaSSOn does NOT own per-workspace RBAC.** It owns tenants, groups, and **tenant-level** roles. Mapping *group → workspace permission* is the relying party's job. Rationale: keeps the broker generic and small; workspace semantics belong to the notes app.
- **N2 — Not a full OIDC Provider.** We implement the specific grants we need (client-credentials; optionally auth-code + PKCE), not the entire OIDC/OAuth surface. No dynamic client registration, no device flow (this time).
- **N3 — No content storage.** GrandpaSSOn never stores notes, documents, or workspace content. It references a `workspace_id` only as an opaque audience value.
- **N4 — No background daemon.** All periodic work (token GC, audit retention) runs via cron / TaskConnect, never a long-running process.
- **N5 — No OCR, crawling, or document parsing.** Those live in the notes app + TaskConnect, not here.

---

## 4. Domain model & terminology

| Term | Meaning |
|------|---------|
| **Subject** | A federated end-user identity (stable `subject_id`, provider-agnostic). Already exists in v0. |
| **Tenant** | An organization/account that owns workspaces. New. |
| **Tenant membership** | A subject's role within a tenant: `owner` \| `admin` \| `member`. |
| **Group** | A named set of subjects within a tenant (e.g. `editors`, `viewers`). Relying parties map groups → workspace permissions. |
| **Relying Party (RP) client** | A confidential web app using login-redirect + `session/exchange` (v0 concept). |
| **Service client** | A non-interactive client using `client_credentials` (new). Used by agents, MCP, TaskConnect. |
| **Personal Access Token (PAT)** | A user-issued, scoped, revocable token so an agent can act on that user's behalf without a browser (new, optional P1). |
| **Reader session** | A lightweight authenticated session for viewing a gated published site (new, P2). |
| **Scope** | A permission string carried by a token (§6.3). |
| **Audience (`aud`)** | The intended consumer of a token (an RP or a specific `workspace_id`). |

---

## 5. Requirements (MoSCoW)

### P0 — Must have

- **R1. Tenancy data model.** Tenants, tenant memberships, groups, group memberships. Idempotent migrations.
- **R2. Claims in session exchange.** Extend the `POST /session/exchange` **response** to include active tenant + role, all tenant memberships, and group slugs (§6.2). Existing response fields remain; additions are backward-compatible.
- **R3. Service clients + client-credentials grant.** `POST /oauth/token` issuing short-lived, scoped, **opaque** access tokens for service clients (§6.1, §6.4).
- **R4. Token introspection.** `POST /oauth/introspect` (RFC 7662-style) so RPs/MCP validate opaque tokens server-side (§6.5).
- **R5. Token revocation.** `POST /oauth/revoke` (RFC 7009-style) + admin revocation (§6.6).
- **R6. Secure token storage & handling.** Tokens stored as hashes only; secrets from env; constant-time comparison; short TTLs (§7).
- **R7. Audit log.** Append-only record of issuance, admin mutations, and auth failures (§8).
- **R8. Admin/management surface (CLI-first).** Create tenants, manage members/groups, register/rotate service clients, list/revoke tokens — via CLI scripts consistent with existing tooling. A minimal admin HTTP surface is P1.
- **R9. Cron-driven maintenance.** Expired-token garbage collection and audit retention as a cron job (extends existing cleanup; callable by TaskConnect).

### P1 — Should have

- **R10. Personal Access Tokens (PATs).** User-issued scoped tokens for agent-on-behalf-of-user access, revocable by the user.
- **R11. Authorization Code + PKCE grant**, formalized for public clients (if any RP needs browser-based token issuance beyond session exchange).
- **R12. Minimal admin HTTP UI** (behind admin auth) mirroring the CLI in R8.
- **R13. Rate limiting** on `/oauth/token` and `/oauth/introspect`.

### P2 — Could have

- **R14. Gated-publishing reader sessions** (§9): `public` / `authenticated` / `private` visibility per site, reader login isolated from editor sessions.
- **R15. Optional signed JWT access tokens** for an RP fast-path (stateless verification, short TTL), alongside opaque + introspection.
- **R16. Key rotation tooling** for JWT signing keys (only if R15 is built).

### Won't have (this iteration)

- Full OIDC discovery/UserInfo beyond what R2 provides; dynamic client registration; device-code flow; SCIM provisioning. Revisit later.

---

## 6. Protocol & endpoint contracts

> All new endpoints live under the existing broker app and are served from `public_html/`. All requests/responses are JSON. All endpoints require HTTPS.

### 6.1 `POST /oauth/token` (P0)

Client-credentials grant for service clients.

**Request (form-encoded or JSON):**
```
grant_type=client_credentials
client_id=<service_client_id>
client_secret=<service_client_secret>
scope=<space-separated scopes>          # optional; defaults to client's allowed scopes
audience=<rp_id | workspace_id>         # optional; narrows token audience
```

**Response 200:**
```json
{
  "access_token": "gpat_live_<opaque-random>",
  "token_type": "Bearer",
  "expires_in": 900,
  "scope": "kb:read",
  "aud": "workspace/abc123"
}
```

**Errors:** RFC 6749 error shape (`invalid_client`, `invalid_scope`, `unauthorized_client`). Never leak whether a `client_id` exists via timing or message differences.

**Acceptance criteria:**
```
Given a valid, active service client with scope "kb:read",
When it POSTs grant_type=client_credentials with scope "kb:read",
Then it receives an opaque access_token with expires_in <= configured max and scope "kb:read".

Given a service client requesting a scope it is not allowed,
When it POSTs to /oauth/token,
Then the response is 400 invalid_scope and no token is issued.

Given an invalid client_secret,
When it POSTs to /oauth/token,
Then the response is 401 invalid_client, the attempt is audit-logged, and the comparison is constant-time.
```

### 6.2 `POST /session/exchange` — extended response (P0)

Existing behavior unchanged; response gains claims. **Additive only.**

v0 top-level fields (`id`, `email`, `display_name`, `status`) **remain flat** — do **not** nest them under a `session` wrapper (base spec / backward compatibility wins). Additive v1 keys sit beside them.

**Response example (flat):**
```json
{
  "id": "sub_...",
  "email": "user@example.com",
  "display_name": "Example User",
  "status": "active",
  "subject": { "id": "sub_...", "email": "user@example.com", "name": "Example User", "idp": "google" },
  "tenant":  { "id": "ten_...", "slug": "acme", "role": "admin" },
  "tenants": [ { "id": "ten_...", "slug": "acme", "role": "admin" } ],
  "groups":  [ "editors", "viewers" ],
  "scopes":  [ "openid", "profile", "email", "tenant:read" ]
}
```

- `tenant` = the active/default tenant for this subject. If the subject belongs to none, `tenant` is `null` and `tenants` is `[]` (RP decides what to do).
- **Active selection (R2):** optional exchange body field `tenant` (id or slug) when the subject is a member; else sticky preference (`user_active_tenant` / `POST /me/active-tenant`); else highest role (`owner` > `admin` > `member`), then lowest slug.
- Group slugs are **tenant-scoped**; the RP interprets them.

**Acceptance criteria:**
```
Given a subject who is an "admin" of tenant "acme" and member of group "editors",
When the RP calls POST /session/exchange,
Then the response includes tenant.role="admin", tenant.slug="acme", and "editors" in groups.

Given a subject with no tenant membership,
When the RP calls POST /session/exchange,
Then tenant is null and tenants is an empty array, and the call still succeeds (200).
```

### 6.3 Scope vocabulary (P0)

Broker-issued, RP-interpreted. Keep small and explicit.

| Scope | Meaning | Typical holder |
|-------|---------|----------------|
| `openid` `profile` `email` | Identity claims | User sessions |
| `tenant:read` | Read tenant/group membership | RP, user tokens |
| `kb:read` | Read a knowledge base / workspace content | AI agents, MCP, retrieval API |
| `kb:write` | Write/ingest content | Trusted services only |
| `publish:read` | Read a gated published site | Reader sessions (P2) |
| `tasks:callback` | Authenticate a background job callback | TaskConnect service client (outbound) |
| `tasks:write` | Submit / ingest tasks inbound | TaskConnect service client (inbound) |

> Workspace-narrowing is expressed via `audience` (`workspace/<id>`), **not** via new scopes per workspace. Rationale: keeps the scope set bounded (Non-goal N1).
>
> TaskConnect treats Environment public ids as `workspace_id` (e.g. `env_…`). Issue tokens with `aud=workspace/<environment_public_id>` (or the client's `default_audience`) so introspection returns both `scope` (including `tasks:write` / `tasks:callback`) and `aud` for dual-mode inbound checks.

### 6.4 Token format (P0 default)

- **Default: opaque random tokens** (`gpat_live_<>=32+ bytes base64url>`), validated via introspection (§6.5). Chosen for **server-side revocation** and simplicity on shared hosting.
- Optional JWT (R15/P2) only as an RP fast-path, short TTL, and must still be revocable-by-TTL.

### 6.5 `POST /oauth/introspect` (P0)

**Request:** `{ "token": "gpat_live_..." }` (caller authenticates as a client).

**Response 200:**
```json
{
  "active": true,
  "sub": "sub_... | null",
  "client_id": "svc_...",
  "scope": "kb:read",
  "aud": "workspace/abc123",
  "tenant": "ten_...",
  "exp": 1730000000
}
```
Inactive/expired/revoked/unknown tokens return `{ "active": false }` — and **nothing else** (no leakage).

**Acceptance criteria:**
```
Given a revoked token,
When introspected,
Then the response is exactly {"active": false}.

Given a valid unexpired token,
When introspected,
Then active=true and scope/aud/exp match issuance.
```

### 6.6 `POST /oauth/revoke` (P0)

RFC 7009-style. Revoking is idempotent and always returns 200 (even for unknown tokens, to avoid enumeration). Admin CLI can revoke by `token_id`, `client_id`, or `subject_id`.

### 6.7 Management CLI (P0) / HTTP admin (P1)

CLI verbs (consistent with existing repo tooling; exact runner per repo convention):
```
tenant:create <slug> <name>
tenant:add-member <tenant> <subject> <role>
group:create <tenant> <slug>
group:add-member <tenant> <group> <subject>
client:create-service <name> --scopes="kb:read" --aud="workspace/<id>"
client:rotate-secret <client_id>
token:list [--client=<id>] [--subject=<id>]
token:revoke <token_id | --client=<id> | --subject=<id>>
```

---

## 7. Security requirements (hard constraints)

These are non-negotiable and derive directly from failure modes we are remediating elsewhere.

- **S1. No hardcoded secrets.** All secrets/keys from `.env`. A test MUST fail the build if any credential-like literal appears in `app/` or in the release zip.
- **S2. Secrets hashed at rest.** Service-client secrets hashed with Argon2id (or bcrypt). Access tokens/PATs stored as **SHA-256 hashes** — never plaintext. Only the hash is queryable.
- **S3. Constant-time comparison** for all secret/token verification. No early-return on mismatch.
- **S4. Short-lived access tokens.** Service-client access tokens default TTL 15 min, hard max configurable (e.g. 60 min). PATs may be long-lived but MUST be scoped, revocable, and show `last_used_at`.
- **S5. Revocation is authoritative.** Opaque-token validation checks `revoked_at IS NULL AND expires_at > now()` on every introspection.
- **S6. PKCE required** for any public client (R11).
- **S7. Transport & cookies.** HTTPS enforced; session cookies `HttpOnly`, `Secure`, `SameSite=Lax` (or stricter). Reader sessions (P2) use a **separate cookie name/scope** from editor sessions.
- **S8. Redirect URIs exact-match allowlisted** per client (preserve v0 behavior; do not weaken).
- **S9. Rate limiting & lockout** on `/oauth/token`, `/oauth/introspect`, and login (R13). Backed by DB counters (no Redis on shared hosting).
- **S10. Audit everything security-relevant** (§8). Failed auth included.
- **S11. Pure-PHP crypto only.** Use `sodium`/`openssl` PHP extensions. **Do not** shell out (`exec`/`shell_exec` are disabled on target hosts).

---

## 8. Audit logging (P0)

Append-only `audit_log` capturing at minimum: `actor_type` (subject|service|admin|system), `actor_id`, `action`, `target`, `client_id`, `ip`, `user_agent`, `result` (success|failure), `created_at`.

Logged actions (non-exhaustive): token issued, token revoked, introspection failure, client created/rotated, tenant/group/member mutation, login success/failure.

Retention: configurable; pruning runs via cron/TaskConnect (§9 of base spec schedules).

---

## 9. Gated publishing — reader sessions (P2)

Only when R14 is scheduled.

- Each published site has `visibility ∈ {public, authenticated, private}` (referenced by opaque `site_id`/`workspace_id`; content lives in the notes app).
- `public` → no auth. `authenticated` → reader must log in via a **reader flow** (`GET /site/{site_id}/login` → IdP → reader session with `publish:read` scope). `private` → tenant-member only.
- Reader sessions are **isolated** from editor sessions (separate cookie, separate scope set). A reader session grants **no** editor capability.

**Acceptance criteria:**
```
Given a site with visibility="authenticated",
When an anonymous request hits it,
Then the reader is redirected to /site/{id}/login and, after IdP auth, receives a reader session scoped to publish:read only.

Given a valid reader session,
When it is presented to an editor endpoint,
Then the editor endpoint rejects it (reader scope grants no editor capability).
```

---

## 10. Cross-project interfaces

### 10.1 Notes app (relying party)
- Consumes extended `session/exchange` claims (§6.2); maps `groups` → per-workspace permissions (owns that logic per N1).
- Validates agent/service tokens via `POST /oauth/introspect`; enforces `aud` = the workspace being accessed.

### 10.2 TaskConnect (service client)
- Registered as a **service client** with scopes `tasks:callback` (outbound callbacks) and/or `tasks:write` (inbound task submission). Add `kb:write` only if it must ingest knowledge content.
- Obtains a client-credentials token (§6.1) and presents it on its at-least-once HTTP callbacks / inbound API, replacing any static shared secret. Because delivery is at-least-once, receivers must treat the token as an identity, not a nonce (idempotency is the receiver's job).
- Set `aud` / `--aud=workspace/<environment_public_id>` so introspection returns the workspace/environment public id TaskConnect uses as `workspace_id` (e.g. `env_…`).
- GrandpaSSOn's own token-GC and audit-retention can be scheduled *as* TaskConnect jobs (or the existing cron) — GrandpaSSOn exposes a protected maintenance endpoint or CLI entrypoint for this.

### 10.3 AI agents / MCP server
- Authenticate via a service client (machine) or a user PAT (R10), scope `kb:read`, `aud=workspace/<id>`.
- The MCP/retrieval layer validates every call by introspection before returning content.

---

## 11. Deployment, migrations & stop line

- **Platform:** PHP 8.2+, MySQL 8.0+, Apache/LiteSpeed, deploy-by-zip to shared hosting. No daemon. Preserve v0 Docker dev + `make test` / `make build`.
- **Migrations:** all new tables via idempotent migrations; safe to re-run on upgrade. No destructive change to v0 tables.
- **Config:** all new settings via `.env` with documented defaults in `.env.example` (TTLs, rate limits, retention).
- **Release:** `make build` must still emit a working `grandpasson-release.zip` containing no secrets (verified by S1 test).

**P0 stop line (definition of done for this spec's first cut):**
> Tenancy model live; `session/exchange` returns tenant/role/group claims; service clients can obtain short-lived opaque tokens via client-credentials; RPs can introspect and revoke; every issuance/failure is audited; no secret ships in the zip; `make test` and `make build` green. **Stop here and request review** before starting P1.

---

## 12. Open questions (resolve before/with implementation)

| # | Question | Default if unresolved | Owner |
|---|----------|-----------------------|-------|
| Q1 | Should tenant role live in GrandpaSSOn or be derived from groups only? | Keep explicit `tenant_members.role` (owner/admin/member) **and** groups. | Joe |
| Q2 | Opaque-only, or ship JWT fast-path now? | Opaque-only for P0 (R15 deferred to P2). | Joe |
| Q3 | Do any RPs need auth-code+PKCE, or is session-exchange enough? | Assume session-exchange suffices; PKCE stays P1 until an RP needs it. | Joe |
| Q4 | Default access-token TTL and hard max? | 15 min default, 60 min max, both env-configurable. | Joe |
| Q5 | Reader-auth (gated publishing) in scope now or later? | Later (P2); design tables but don't build until scheduled. | Joe |
| Q6 | Where is the canonical `workspace_id` registry — notes app only, or mirrored here? | Notes app is canonical; GrandpaSSOn treats it as opaque `aud`. | Joe |

---

*This document extends `docs/grandpasson-spec.md`. Implement P0 in order, keep v0 green, and stop at the §11 stop line for review.*
