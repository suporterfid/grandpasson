# GrandpaSSOn

SSO that runs where your grandpa's cPanel still lives.

Minimalist PHP + MySQL SSO broker for shared hosting. Federates identity from Google, Microsoft, and GitHub. Develop and build in Docker; deploy a zip to cPanel-style PHP/MySQL hosts.

## Quickstart (Docker)

```bash
cp .env.example .env
make up
# Broker:      http://localhost:8080
# phpMyAdmin:  make tools  (optional; http://localhost:8081)
# Split nginx+php-fpm (when Docker networking allows): docker compose --profile split up -d --build
```

Stop with `make down`.

## Docs

| Doc | Purpose |
|---|---|
| [docs/grandpasson-spec.md](docs/grandpasson-spec.md) | Product/protocol authority |
| [docs/grandpasson-v0-mvp-plan.md](docs/grandpasson-v0-mvp-plan.md) | v0 task list (M1+ after Foundation) |
| [docs/plans/2026-07-21-001-feat-post-review-v0-next-steps-plan.md](docs/plans/2026-07-21-001-feat-post-review-v0-next-steps-plan.md) | Why exchange / allowlist / Foundation sequencing |

## License

MIT — see [LICENSE](LICENSE).
