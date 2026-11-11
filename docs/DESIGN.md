# payments-outbox — спецификация v0.1

Демо-приложение: сервис заказов и платежей, в котором **azhimov/laravel-idempotency** и **azhimov/laravel-outbox** работают вместе. Главная цель — **доказать гарантии на практике**: клиент повторяет запросы, relay падает, брокер перезапускается, а деньги не списываются дважды и ни одно событие не теряется. Всё поднимается одной командой `docker compose up`.

Домен вымышленный: магазин «Demo Store», фейковый эквайринг. Никакого кода, названий, схем и данных SmartGas, Freedom Pay и других работодателей.

## Решения перед реализацией (11.11.2026)
- **A. Неизвестный исход у PSP** (таймаут, 5xx): платёж остаётся `processing`, API отвечает `202` со статусом pending. `failed` — только при явном отказе «не обработано». Иначе клиент повторит с новым ключом и получит двойное списание.
- **B. Транзакции:** tx1 создаёт платёж `processing` → вызов PSP вне транзакции → tx2 (статус платежа, заказ `paid`, `payment.succeeded` в outbox).
- **Сверка:** fake-psp отдаёт `GET /payments/{payment_id}`; `payments:reconcile` каждую минуту берёт платежи в `processing` старше N секунд и доводит их до финального статуса **тем же use case, что webhook**. Webhook и reconcile идемпотентны друг относительно друга: первый переводит, второй — no-op. В Limitations: застрявшие платежи закрывает reconcile.
- **C.** Наш `payment_id` — idempotency-ключ к PSP; fake-psp на повтор с тем же ключом возвращает тот же результат без второго списания.
- **D.** Таблица `merchants`, sha256 от API-ключа, демо-ключ из seeder; свой `ScopeResolver` по `merchant_id`.
- **E. Ledger — двойная запись:** `ledger_accounts` (psp_clearing, merchant_payable, refunds…) и `ledger_entries` (transaction_id, account, amount в минорных единицах, direction). Сумма по transaction_id = 0 — проверяется в коде и в `demo:verify`. Возврат — обратные проводки, не удаление.
- **F. Consumer:** `ledger:consume` (php-amqplib `basic_consume`, prefetch из конфига), ack после коммита inbox, ошибка → reject в DLQ `ledger.dlq`, graceful shutdown по SIGTERM, `ledger:dlq-retry`. Топологию объявляет `rabbitmq:setup`.
- **G.** Consumer-сторона чтения — в пакет: laravel-outbox 0.2.0 с `RabbitMqTransport::receive()` / `RedisStreamsTransport::receive()`. Порядок: шаги 2–7 приложения → релиз пакета 0.2.0 → шаг 8.
- **H.** `make chaos` — Makefile на хосте (kill relay, restart rabbitmq), нагрузка и сверка — `demo:load` / `demo:verify` в cli-контейнере. Метрики — `promphp/prometheus_client_php` с хранилищем в Redis.
- Валюта только KZT; predis; подпись webhook HMAC-SHA256 над `timestamp.body`, окно 5 минут; правило «фреймворк не проникает в Domain» — своё PHPStan-правило в `tools/phpstan`.

## Сценарий
1. `POST /api/orders`: создать заказ (позиции, сумма, валюта KZT).
2. `POST /api/orders/{id}/payments` с заголовком `Idempotency-Key`: создать платёж и вызвать фейковый PSP.
   - PSP отвечает синхронно (`succeeded` / `declined` / `pending`), либо падает с таймаутом или 5xx.
   - В **одной транзакции**: платёж меняет статус, заказ переходит в `paid`, в outbox пишется `payment.succeeded` (aggregateId = order id).
   - Для `pending` финальный статус придёт webhook-ом от PSP.
3. `POST /api/webhooks/psp`: webhook от PSP с подписью HMAC. Защищён inbox (id события PSP), дубли webhook-ов не меняют состояние.
4. **Relay** (`outbox:relay`) доставляет события в RabbitMQ (exchange `payments`).
5. **Consumer «ledger»** (отдельный процесс): читает `payment.succeeded` / `payment.refunded` и пишет проводки в таблицу `ledger_entries` через inbox. Повторная доставка проводку не дублирует.
6. `POST /api/payments/{id}/refunds` с `Idempotency-Key`: частичный или полный возврат, событие `payment.refunded`.

## Архитектура
Одно Laravel-приложение (PHP 8.4, Laravel 13), Clean Architecture:
```
app/
  Domain/        Order, Payment, Refund, Money (value object), статусы-enum, доменные исключения
  Application/   UseCases (CreateOrder, CreatePayment, HandlePspWebhook, CreateRefund), DTO, порты (PaymentGateway, Clock)
  Infrastructure/ Eloquent-репозитории, FakePspGateway (HTTP-клиент), outbox/inbox wiring, ledger consumer
  Presentation/  Controllers, FormRequests, API Resources, единый формат ответа
```
- Eloquent не протекает в Domain: проверяется PHPStan-правилами (это заодно черновик будущего пакета laravel-architecture-rules).
- Деньги — integer в минорных единицах + валюта, никаких float.
- Конечный автомат статусов платежа: `created → processing → succeeded | declined | failed`, `succeeded → partially_refunded | refunded`. Недопустимый переход даёт доменное исключение и тест на каждый переход.

### Сервисы в docker compose
| Сервис | Что делает |
|---|---|
| `api` | php-fpm + nginx, API |
| `relay` | `php artisan outbox:relay`, 2 реплики, чтобы показать параллельный relay |
| `ledger` | consumer из RabbitMQ с inbox |
| `fake-psp` | маленький сервис (тот же образ, отдельный entrypoint): задержки, процент отказов, таймауты, webhooks — всё настраивается env |
| `postgres` | PostgreSQL 17 |
| `rabbitmq` | RabbitMQ 4 management |
| `redis` | кэш + хранилище idempotency по умолчанию (переключается на database) |
| `prometheus`, `grafana` | метрики и готовый дашборд (provisioning из репозитория) |

## Наблюдаемость
- Эндпоинт `/metrics`: запросы API, idempotency (replayed / conflict / mismatch — из событий пакета), outbox (stored / published / failed / lag), ledger (processed / duplicates skipped), PSP (latency, ошибки).
- Дашборд Grafana «Payments»: RPS, ошибки, отставание outbox, повторы idempotency, дубли в inbox, баланс ledger.
- Логи в JSON с `request_id`, `idempotency_key`, `message_id`.

## Доказательство гарантий — `make chaos`
Главная фишка README. Скрипт (k6 или PHP-команда `demo:chaos`):
1. Создаёт N заказов (по умолчанию 500) и оплачивает каждый с **повторами**: 1–3 одинаковых запроса на ключ, часть параллельно.
2. Включает в fake-psp 10% таймаутов и 5% 5xx.
3. В середине прогона `docker compose kill relay` и `restart rabbitmq`, потом поднимает обратно.
4. Ждёт, пока отставание outbox станет 0.
5. **Сверка** (`demo:verify`): по каждому заказу ровно одна успешная оплата; сумма проводок в ledger = сумма оплаченных заказов минус возвраты; ноль дублей; ноль потерянных событий.
6. Печатает отчёт и сохраняет скриншот или JSON для README.

Результат прогона — таблица в README: «500 заказов, 1 140 запросов с повторами, relay убит 1 раз, RabbitMQ перезапущен 1 раз → 0 двойных списаний, 0 потерянных событий».

## API
- Единый формат ответа и ошибок (`data` / `error.code` / `error.message`), коды ошибок idempotency — из пакета.
- OpenAPI 3.1 спецификация `docs/openapi.yaml` + Swagger UI в compose.
- Аутентификация: простые API-ключи мерчанта (заголовок `X-Api-Key`), scope idempotency по мерчанту.

## Тесты
- Unit: Domain (автомат статусов, Money, правила возврата).
- Feature: каждый эндпоинт, включая повтор с тем же ключом, другое тело → 422, параллельный → 409.
- Integration: транзакция «платёж + outbox» откатывается целиком при ошибке; ledger не дублирует при повторной доставке; webhook дубли.
- Contract: FakePspGateway против fake-psp.
- Покрытие ≥ 85% (приложение, а не пакет), Larastan max, Pint.
- CI: тесты на PostgreSQL + RabbitMQ + Redis как service containers; отдельная джоба `chaos-smoke` с маленьким N (50 заказов, 1 kill relay) — сверка должна пройти.

## README
Проблема (двойное списание и потерянные события) → архитектура (Mermaid: компоненты и последовательность платежа) → `docker compose up` и curl-примеры → `make chaos` и таблица результата → скриншот дашборда Grafana → решения («Почему так»: outbox вместо dual write, inbox, idempotency scope по мерчанту, integer money) → ограничения → ссылки на оба пакета.

## Порядок шагов (один шаг — один коммит)
1. Скелет Laravel 13, docker compose (api, postgres, redis, rabbitmq), CI, Larastan, Pint.
2. Domain: Money, Order, Payment, статусы, тесты.
3. Заказы: API + репозиторий + тесты.
4. fake-psp сервис + FakePspGateway + contract-тесты.
5. Платёж: use case, idempotency middleware, транзакция с outbox.
6. Relay в compose (2 реплики) + RabbitMQ topology.
7. Webhooks PSP: HMAC, inbox, pending → final.
8. Ledger consumer с inbox.
9. Возвраты.
10. Метрики, `/metrics`, Prometheus + Grafana с дашбордом.
11. OpenAPI + Swagger UI.
12. `demo:chaos` + `demo:verify` + `make chaos`, джоба `chaos-smoke` в CI.
13. README, скриншоты, CHANGELOG.

## Definition of Done v0.1.0
Чеклист готовности репозитория; `docker compose up` с нуля поднимает всё на чистой машине; `make chaos` проходит со сверкой 0/0; CI зелёный, включая `chaos-smoke`; README с реальной таблицей результата и скриншотом дашборда.
