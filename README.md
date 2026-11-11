# payments-outbox

A demo payments service that shows [laravel-idempotency](https://github.com/azhimov/laravel-idempotency) and [laravel-outbox](https://github.com/azhimov/laravel-outbox) working together: client retries, relay crashes and broker restarts must not charge twice or lose an event.

Work in progress. The full README comes with the first release.

```bash
make up      # docker compose: api, nginx, PostgreSQL, Redis, RabbitMQ
make check   # Pint, Larastan, Pest
```
