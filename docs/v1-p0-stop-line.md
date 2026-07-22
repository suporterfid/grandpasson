# V1 P0 stop line

This page is the **P0 Definition of Done** checklist for GrandpaSSOn v1 machine identity (see [spec extension §11](grandpasson-spec-v1-extension.md#11-deployment-migrations--stop-line) and [plan](plans/2026-07-22-001-feat-v1-p0-tenancy-machine-identity-plan.md)).

Do **not** start P1 (admin UI) or P2 (JWKS / RS256) until this list is reviewed and accepted.

## Completed (shipped)

| Item | Evidence |
|------|----------|
| Tenants + groups + membership tables | Migrations `007`–`009`; `TenantRepository` / `GroupRepository` |
| Session / exchange claims include `tenant` + `groups` | `SessionClaimsResolver`; `docs/client-integration.md` |
| Service clients + hashed secrets | Migration `012`; `ClientSecretHasher`; `ServiceClientRepository` |
| Opaque access tokens (`gpat_live_…`) | Migration `013`; `AccessTokenIssuer` / `AccessTokenRepository` |
| `POST /oauth/token` (client_credentials) | `OAuthTokenController`; rate-limited |
| `POST /oauth/introspect` | `OAuthIntrospectController`; active/inactive JSON |
| `POST /oauth/revoke` + admin revoke helpers | `OAuthRevokeController`; `AccessTokenRevoker` |
| Admin CLI (`cron/admin.php`) | `AdminCommandRunner`; tenants / groups / service-clients |
| Cron GC for access tokens + audit retention | `CleanupJob`; `docs/deployment.md` |
| Shared route registration | `App\Http\AppRoutes` used by `public_html/index.php` |
| Docs: HTTP surface + claims | README, this page, client-integration, deployment |
| S1 release scan includes `app/` | `scripts/build-release.sh`; `ReleaseArtifactGateTest` |
| Migration parity (`app` ↔ `docker/mysql/init`) | `make check-migrations` |
| Automated regression suite | `make test` (PHPUnit) |

## Explicitly out of P0

- Admin UI for tenants / service clients (P1)
- JWKS / RS256 / signed JWTs (P2)
- Changing human-session cookie crypto or browser OIDC authorize UX beyond claims

## Gate commands (run before calling P0 done)

```bash
make test
make check-migrations
make build   # then confirm zip S1 scan is clean
```

Human review should confirm the table above matches production intent before opening P1/P2 work.
