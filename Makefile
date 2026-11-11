.PHONY: up down logs check test-pgsql chaos

up: ## Build and start everything
	docker compose up --build -d

down:
	docker compose down -v

logs:
	docker compose logs -f api

check: ## Pint, Larastan, Pest (SQLite)
	composer check

test-pgsql: ## Pest against the compose PostgreSQL and RabbitMQ (make up first), in a separate payments_test database and RabbitMQ vhost
	docker compose exec -T postgres sh -c 'psql -U payments -d payments -tc "select 1 from pg_database where datname = '"'"'payments_test'"'"'" | grep -q 1 || createdb -U payments payments_test'
	docker compose exec -T rabbitmq sh -c 'rabbitmqctl -q add_vhost payments_test 2>/dev/null; rabbitmqctl -q set_permissions -p payments_test guest ".*" ".*" ".*"'
	RABBITMQ_TESTS=1 OUTBOX_RABBITMQ_HOST=127.0.0.1 OUTBOX_RABBITMQ_PORT=56730 OUTBOX_RABBITMQ_VHOST=payments_test DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=55440 DB_DATABASE=payments_test DB_USERNAME=payments DB_PASSWORD=payments vendor/bin/pest

ORDERS ?= 500
KILL_AFTER ?= 20
CHAOS_ENV = FAKE_PSP_TIMEOUT_RATE=0.10 FAKE_PSP_ERROR_RATE=0.05 PSP_RECONCILE_AFTER=10

chaos: ## Prove it: retried payments, PSP timeouts and 5xx, the relay killed, RabbitMQ restarted, then reconcile
	mkdir -p storage/chaos
	docker compose down --remove-orphans
	$(CHAOS_ENV) docker compose up -d --build --wait
	@echo ">> chaos: $(ORDERS) orders, 10% PSP timeouts, 5% PSP 503"
	docker compose run --rm cli php artisan demo:load --orders=$(ORDERS) & LOAD=$$!; \
	sleep $(KILL_AFTER); echo ">> chaos: kill the relay"; docker compose kill relay; \
	sleep 10; echo ">> chaos: restart RabbitMQ"; docker compose restart rabbitmq; \
	sleep 10; echo ">> chaos: relay back"; $(CHAOS_ENV) docker compose up -d relay; \
	wait $$LOAD
	docker compose run --rm cli php artisan demo:verify --wait=300 --report=storage/chaos/report.json
