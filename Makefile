.PHONY: up down migrate test build check-migrations

up:
	@test -f .env || cp .env.example .env
	docker compose up -d --build

tools:
	docker compose --profile tools up -d phpmyadmin

down:
	docker compose --profile tools down

migrate:
	php cron/migrate.php

check-migrations:
	@diff -rq app/Infrastructure/Db/Migrations docker/mysql/init

test:
	php vendor/bin/phpunit

build:
	docker compose -f docker-compose.build.yml run --rm build
