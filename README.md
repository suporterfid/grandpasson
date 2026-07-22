# GrandpaSSOn

SSO that runs where your grandpa's cPanel still lives.

Minimalist PHP + MySQL SSO broker for shared hosting. Federates identity from Google, Microsoft, and GitHub. Develop and build in Docker; deploy a zip to cPanel-style PHP/MySQL hosts.

**v1 P0 (shipped):** tenancy claims on `POST /session/exchange`, service-client machine tokens (`/oauth/token` · `/oauth/introspect` · `/oauth/revoke`), admin CLI, audit log, and cron GC. P1/P2 (PATs, admin HTTP UI, gated publishing) are deferred — see the [P0 stop line](docs/v1-p0-stop-line.md).

## Quickstart (Docker)

```bash
cp .env.example .env
make up
# Broker:      http://localhost:8080
# phpMyAdmin:  make tools  (optional; http://localhost:8081)
# Cron cleanup: make cron  (optional; sessions/auth-codes/tokens/audit)
# Split nginx+php-fpm (when Docker networking allows): docker compose --profile split up -d --build
```

```bash
make test
make build    # writes grandpasson-release.zip for shared-hosting upload
make check-migrations
```

Stop with `make down`.

## HTTP surface (v0 + v1 P0)

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/` | Health |
| `GET` | `/login`, `/login/{provider}` | Browser login |
| `GET` | `/callback/{provider}` | IdP callback |
| `POST` | `/logout` | End broker session |
| `GET` | `/session` | Cookie session (same-host) |
| `POST` | `/session/exchange` | RP auth-code → user (+ tenant/group claims) |
| `POST` | `/oauth/token` | Service client credentials → opaque token |
| `POST` | `/oauth/introspect` | Validate opaque token |
| `POST` | `/oauth/revoke` | Revoke opaque token |
| `GET` | `/admin` | Minimal admin UI (requires `ADMIN_API_TOKEN`) |
| `POST` | `/admin/api` | Admin JSON API (Bearer / `X-Admin-Token`) |

Admin (CLI, ships in zip): `php cron/admin.php …` — tenants, groups, service clients, access tokens, PATs.

## Deploy

See **[docs/deployment.md](docs/deployment.md)** for shared-hosting steps (migrations, cPanel cron, HTTPS, IdP redirect URIs).

App developers: **[docs/client-integration.md](docs/client-integration.md)** (RP login + exchange, machine tokens).

## Docs

| Doc | Purpose |
|---|---|
| [docs/deployment.md](docs/deployment.md) | Shared-hosting deploy checklist |
| [docs/client-integration.md](docs/client-integration.md) | Relying-party login + exchange + machine tokens |
| [docs/v1-p0-stop-line.md](docs/v1-p0-stop-line.md) | P0 completion checklist (request review before P1) |
| [docs/grandpasson-spec.md](docs/grandpasson-spec.md) | Product/protocol authority (v0) |
| [docs/grandpasson-spec-v1-extension.md](docs/grandpasson-spec-v1-extension.md) | v1 additive: tenancy · machine identity · gated publishing |
| [docs/grandpasson-v0-mvp-plan.md](docs/grandpasson-v0-mvp-plan.md) | v0 task list |
| [docs/plans/2026-07-22-001-feat-v1-p0-tenancy-machine-identity-plan.md](docs/plans/2026-07-22-001-feat-v1-p0-tenancy-machine-identity-plan.md) | v1 P0 implementation plan |
| [docs/plans/2026-07-21-001-feat-post-review-v0-next-steps-plan.md](docs/plans/2026-07-21-001-feat-post-review-v0-next-steps-plan.md) | Post-review sequencing / exchange |

## License

MIT — see [LICENSE](LICENSE).
