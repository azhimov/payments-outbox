<?php

declare(strict_types=1);

/*
| Broker topology of the demo. The connection settings are the same ones the outbox
| relay uses (OUTBOX_RABBITMQ_*).
*/

return [
    'connection' => [
        'host' => env('OUTBOX_RABBITMQ_HOST', '127.0.0.1'),
        'port' => (int) env('OUTBOX_RABBITMQ_PORT', 5672),
        'user' => env('OUTBOX_RABBITMQ_USER', 'guest'),
        'password' => env('OUTBOX_RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('OUTBOX_RABBITMQ_VHOST', '/'),
    ],

    // The relay publishes here: exchange = outbox topic, routing key = message type.
    'exchange' => 'payments',

    'ledger' => [
        'queue' => 'ledger',
        'routing_keys' => ['payment.succeeded', 'payment.refunded'],
        // Rejected messages go to the DLX and end up in ledger.dlq for ledger:dlq-retry.
        'dead_letter_exchange' => 'payments.dlx',
        'dead_letter_queue' => 'ledger.dlq',
        'prefetch' => (int) env('LEDGER_PREFETCH', 10),
    ],
];
