# GrandpaSSOn

SSO that runs where your grandpa's cPanel still lives.

Minimalist PHP + MySQL SSO broker for shared hosting. Federates identity from Google, Microsoft, and GitHub. Develop and build in Docker; deploy a zip to cPanel-style PHP/MySQL hosts.

## Quickstart (Docker)

```bash
cp .env.example .env
make up
# Broker:      http://localhost:8080
# phpMyAdmin:  make tools  (optional; http://localhost:8081)
# Cron cleanup: make cron  (optional; spec §15 schedules)
# Split nginx+php-fpm (when Docker networking allows): docker compose --profile split up -d --build
```

```bash
make test
make build    # writes grandpasson-release.zip for shared-hosting upload
```

Stop with `make down`.

## Deploy

See **[docs/deployment.md](docs/deployment.md)** for shared-hosting steps (migrations, cPanel cron, HTTPS, IdP redirect URIs).

## Docs

| Doc | Purpose |
|---|---|
| [docs/deployment.md](docs/deployment.md) | Shared-hosting deploy checklist |
| [docs/grandpasson-spec.md](docs/grandpasson-spec.md) | Product/protocol authority |
| [docs/grandpasson-v0-mvp-plan.md](docs/grandpasson-v0-mvp-plan.md) | v0 task list |
| [docs/plans/2026-07-21-001-feat-post-review-v0-next-steps-plan.md](docs/plans/2026-07-21-001-feat-post-review-v0-next-steps-plan.md) | Post-review sequencing / exchange |

## License

MIT — see [LICENSE](LICENSE).
