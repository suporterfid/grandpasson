.PHONY: up down migrate test build check-migrations

up:
	@test -f .env || cp .env.example .env
	docker compose up -d --build

tools:
	docker compose --profile tools up -d phpmyadmin

down:
	docker compose --profile tools down

migrate:
	@echo "Migrations auto-applied via docker-entrypoint-initdb.d on first MySQL boot"

check-migrations:
	@diff -rq app/Infrastructure/Db/Migrations docker/mysql/init

test:
	docker compose exec php php vendor/bin/phpunit

build:
	docker compose -f docker-compose.build.yml run --rm build
