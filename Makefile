.PHONY: up down migrate test build check-migrations tools cron

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
