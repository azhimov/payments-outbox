.PHONY: up down logs check test test-pgsql

up: ## Build and start everything
	docker compose up --build -d

down:
	docker compose down -v

logs:
	docker compose logs -f api

check: ## Pint, Larastan, Pest (SQLite)
	composer check

test-pgsql: ## Pest against the compose PostgreSQL and RabbitMQ (make up first), in a separate payments_test database
	docker compose exec -T postgres sh -c 'psql -U payments -d payments -tc "select 1 from pg_database where datname = '"'"'payments_test'"'"'" | grep -q 1 || createdb -U payments payments_test'
	RABBITMQ_TESTS=1 OUTBOX_RABBITMQ_HOST=127.0.0.1 OUTBOX_RABBITMQ_PORT=56730 DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55440 DB_DATABASE=payments_test DB_USERNAME=payments DB_PASSWORD=payments vendor/bin/pest
