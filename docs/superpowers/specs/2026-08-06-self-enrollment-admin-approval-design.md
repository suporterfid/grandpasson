# Self-enrollment with admin approval

Status: approved (design)
Date: 2026-08-06
Repo: grandpasson (primary). jotter and other federated apps require no changes.

## Problem

`UserProvisioner::resolve()` auto-creates users with `status='active'` on first
login (OAuth or email OTP) whenever the email domain passes
`ALLOWED_EMAIL_DOMAINS` (or `APP_ENV` is dev/local). There is no admin
approval step before a new identity can use any federated app. jotter's own
access control (`Membership` rows, admin-created only) only gates which
*workspace* a user sees — it assumes the identity itself is already trusted.

## Goal

Add a signup + admin-approval gate entirely inside GrandpaSSOn:

```
New user -> /signup (email or OAuth) -> user created status=pending
                                          |
Admin reviews (CLI or /admin UI) -> approve / reject
                                          |
status=active -> normal login works (OAuth or email OTP)
```

Federated apps (jotter, taskconnect, …) need zero changes: a pending or
rejected user never obtains a valid `AUTHSESSID` session, so from their
perspective such a user simply never authenticated.

## Data model

`users.status` ENUM gains two values: `active`, `disabled`, `pending`,
`rejected` (was `active`, `disabled`).

New table `signup_requests` (1:1 with the `users` row it was created for):

| column | type | notes |
|---|---|---|
| id | CHAR(36) PK | |
| user_id | CHAR(36) FK -> users.id | set at creation time (user row is created together with the request) |
| email | VARCHAR(255) | snapshot at signup time |
| display_name | VARCHAR(255) | |
| justification | TEXT | free text from the requester |
| source | ENUM('email','google','microsoft','github') | |
| status | ENUM('pending','approved','rejected') | |
| reviewed_by | VARCHAR(255) NULL | admin identifier (token label / CLI operator) |
| reviewed_at | DATETIME NULL | |
| rejection_reason | TEXT NULL | internal only, never shown to the user |
| created_at, updated_at | DATETIME | |

Rejected users are kept (`users.status='rejected'`, `signup_requests.status='rejected'`)
for audit — never deleted. They cannot re-apply through `/signup` (existing
email/subject match short-circuits to "not approved"). An admin can move them
back to `pending` via `user:reopen`.

## Signup flow

### Email path (`/signup`)

1. Form collects: name, email, justification.
2. Domain gate: if `ALLOWED_EMAIL_DOMAINS` is configured, reject early with a
   clear message (mirrors `UserProvisioner::assertMayAutoCreate`) — avoids
   generating pending noise for admins from disallowed domains.
3. Send an OTP code to the email (reuses the existing email-OTP code path
   from `EmailOtpLoginController`).
4. On verified code: create `users` (status=pending), `linked_identities`
   (provider=email), `signup_requests` (status=pending, source=email) in one
   transaction.
5. Show "signup received, awaiting admin approval".

### OAuth path (`/login/{provider}` -> callback)

1. Callback resolves the identity as today (`NormalizedIdentity`; email is
   already verified by the IdP).
2. In `UserProvisioner::resolve()`, when no existing user matches (by subject
   or by email): instead of auto-creating `active`, issue a short-lived
   signed token (HMAC, ~10 min TTL) carrying provider+subject+email+name, and
   redirect to `/signup/complete?token=...`.
3. That screen pre-fills name/email (read-only, already verified) and asks
   only for justification.
4. On submit (token re-validated): create `users` (pending),
   `linked_identities` (provider=google/microsoft/github),
   `signup_requests` (pending, source=that provider).
5. Same "awaiting approval" screen as the email path.

### Login while pending/rejected

Any login attempt (OAuth callback or email OTP verify) for an existing user
whose `status` is `pending` or `rejected` must not establish a session.
Show a specific message:
- `pending`: "your account is awaiting admin approval."
- `rejected`: "your signup was not approved." (no internal reason shown)

## Admin approval

New verbs added to `AdminCommandRunner` (mirrors existing verb pattern, so
they appear automatically in both `cron/admin.php` CLI and the generic
`/admin` HTML UI):

- `user:list-pending` — lists `signup_requests` where status=pending (email,
  name, justification, source, created_at).
- `user:approve <user_id>` — sets `users.status=active`,
  `signup_requests.status=approved`, `reviewed_by`, `reviewed_at`. Sends
  approval email.
- `user:reject <user_id> --reason="..."` — sets `users.status=rejected`,
  `signup_requests.status=rejected`, stores `rejection_reason`. Sends
  rejection email (without the internal reason).
- `user:reopen <user_id>` — moves `rejected`/`disabled` back to `pending` (no
  new `signup_requests` row; reuses the existing one).

All four verbs call the existing `auditMutation()` helper, same as other
`AdminCommandRunner` verbs.

## Notifications

Reuses `Infrastructure/Mail` (already used for OTP emails). New config:
`ADMIN_NOTIFICATION_EMAILS` (comma-separated env var) — recipients for new
pending-signup alerts.

- New signup (email-verified or OAuth-completed) -> email to
  `ADMIN_NOTIFICATION_EMAILS`: name, email, justification, source, link to
  `/admin`.
- Approved -> email to the user: account approved, link to log in.
- Rejected -> email to the user: signup not approved (no internal reason).

Mail delivery failure must not block the DB transaction — approve/reject
persist regardless of mail success; failures are logged.

## Security

- Domain gate re-applied at `/signup` (same rule as today's
  `assertMayAutoCreate`), when `ALLOWED_EMAIL_DOMAINS` is configured.
- Rate limiting on `/signup`, `/signup/complete`, and OTP send, via the
  existing `RateLimitGate`.
- CSRF token on all signup forms (existing `Csrf` helper).
- OAuth completion token: HMAC-signed with the broker secret, ~10 min TTL,
  single use, bound to provider+subject+email — prevents completing a signup
  with a forged identity.

## Impact on jotter / other federated apps

None. `GrandpaSSOnIdentityProvider` already treats "no valid session" as
unauthenticated. `pending`/`rejected` never produce a valid `AUTHSESSID`
session, so downstream apps are unaware these states exist. Workspace-level
access (`Membership` rows in jotter) remains a separate, admin-driven step,
unchanged.

## Testing

- Unit: `UserProvisioner` (pending instead of active on auto-create),
  `signup_requests` migration, new `AdminCommandRunner` verbs
  (approve/reject/reopen/list-pending state transitions).
- Integration: full email signup (OTP -> pending -> approve -> login
  succeeds), full OAuth signup (callback -> complete -> pending -> approve ->
  login succeeds), login blocked while pending/rejected with correct
  messages, domain-gate rejection, rate limiting, mail-failure does not block
  approve/reject.
- E2E (Playwright, if present for grandpasson): signup -> pending screen ->
  (simulate admin approval via CLI) -> login succeeds.

## Out of scope

- Workspace/tenant-level access requests inside jotter (stays manual, admin
  adds `Membership` rows as today).
- Re-apply flow for rejected users (admin must `user:reopen` explicitly).
- Self-service "check my signup status" page beyond the login-time message.
