<?php

declare(strict_types=1);

/*
| The fake PSP shipped with the demo (routes under /psp, enabled in its own container).
| Card tokens pick a deterministic outcome; tok_visa uses the random rates below.
*/

return [
    'enabled' => filter_var(env('FAKE_PSP_ENABLED', false), FILTER_VALIDATE_BOOL),

    // Shares of tok_visa charges, 0..1. The rest succeed.
    'rates' => [
        'decline' => (float) env('FAKE_PSP_DECLINE_RATE', 0.05),
        'pending' => (float) env('FAKE_PSP_PENDING_RATE', 0.05),
        // Charged, but the answer comes after the client gave up.
        'timeout' => (float) env('FAKE_PSP_TIMEOUT_RATE', 0.0),
        // 503 before charging.
        'error' => (float) env('FAKE_PSP_ERROR_RATE', 0.0),
    ],

    'latency_ms' => (int) env('FAKE_PSP_LATENCY_MS', 50),

    // Pending charges get their final status by webhook after this delay.
    'webhook_delay_ms' => (int) env('FAKE_PSP_WEBHOOK_DELAY_MS', 2000),
    // Share of webhooks sent twice with the same event id (real PSPs do retry).
    'webhook_duplicate_rate' => (float) env('FAKE_PSP_WEBHOOK_DUPLICATE_RATE', 0.2),
    // How long a "timeout" answer is held back; keep it above services.psp.timeout.
    'timeout_sleep_ms' => (int) env('FAKE_PSP_TIMEOUT_SLEEP_MS', 5000),
];
