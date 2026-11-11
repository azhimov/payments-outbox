.PHONY: up down logs check test test-pgsql

up: ## Build and start everything
	docker compose up --build -d

down:
	docker compose down -v

logs:
	docker compose logs -f api

check: ## Pint, Larastan, Pest (SQLite)
	composer check

test-pgsql: ## Pest against the compose PostgreSQL (make up first)
	DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55440 DB_DATABASE=payments DB_USERNAME=payments DB_PASSWORD=payments vendor/bin/pest
