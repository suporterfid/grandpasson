.PHONY: up down migrate test build check-migrations tools cron seed-client

up:
	@test -f .env || cp .env.example .env
	docker compose up -d --build

tools:
	docker compose --profile tools up -d phpmyadmin

cron:
	docker compose --profile cron up -d --build cron

down:
	docker compose --profile tools --profile cron down

migrate:
	php cron/migrate.php

seed-client:
	@test -n "$(CLIENT_ID)" || (echo "CLIENT_ID is required" >&2; exit 1)
	@test -n "$(REDIRECT_URI)" || (echo "REDIRECT_URI is required" >&2; exit 1)
	@test -n "$(SECRET)" || (echo "SECRET is required" >&2; exit 1)
	php cron/seed_oauth_client.php \
		--client-id="$(CLIENT_ID)" \
		--name="$(or $(NAME),$(CLIENT_ID))" \
		--redirect-uri="$(REDIRECT_URI)" \
		--secret="$(SECRET)"

check-migrations:
	@diff -rq app/Infrastructure/Db/Migrations docker/mysql/init

test:
	php vendor/bin/phpunit

build:
	docker compose -f docker-compose.build.yml run --rm build

cleanup-sessions:
	php cron/cleanup_sessions.php

cleanup-auth-codes:
	php cron/cleanup_auth_codes.php
