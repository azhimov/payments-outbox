# Changelog

All notable changes to this project are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - 2026-11-12

### Added
- Orders and payments API for the fictional Demo Store: merchants with API keys (sha256), per-merchant Idempotency-Key on payments and refunds (azhimov/laravel-idempotency).
- Payment state machine (created → processing → succeeded | declined | failed → partially_refunded | refunded) with integer money.
- Charging outside any transaction; payment, order and `payment.succeeded` committed together through azhimov/laravel-outbox; unknown PSP outcomes stay processing.
- Signed PSP webhooks (HMAC-SHA256 over timestamp.body, 5-minute window) deduplicated by the inbox, and `payments:reconcile` every minute, both through one idempotent `PaymentOutcomes`.
- Refunds with ids derived from the Idempotency-Key, `payment.refunded` on the order's aggregate; `refunds:reconcile` every minute finishes refunds left processing after an unclear PSP answer, through the same idempotent `RefundOutcomes` as client retries.
- Double-entry ledger fed from RabbitMQ (`ledger:consume`, inbox, DLQ, `ledger:dlq-retry`, graceful shutdown).
- Fake PSP with deterministic tokens, random failure rates, late answers and duplicate webhooks.
- Prometheus metrics and a provisioned Grafana dashboard; OpenAPI 3.1 with Swagger UI.
- `make chaos` (`demo:load` + `demo:verify`) and a chaos-smoke CI job.
- nginx answers 502 `api_unreachable` / 504 `api_timeout` in the API's JSON error format when php-fpm cannot be reached, documented in OpenAPI.
- docker compose: api, nginx, fake-psp, relay ×2, ledger, scheduler, PostgreSQL 17, Redis 7, RabbitMQ 4, Prometheus, Grafana, Swagger UI.

[0.1.0]: https://github.com/azhimov/payments-outbox/releases/tag/v0.1.0
