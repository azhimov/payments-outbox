<?php

declare(strict_types=1);

return [

    // The payment service provider the API charges through (the fake one in this demo).
    'psp' => [
        'base_url' => env('PSP_BASE_URL', 'http://fake-psp:8000'),
        // Seconds. A slower answer counts as "outcome unknown": the payment stays processing.
        'timeout' => (float) env('PSP_TIMEOUT', 3),
        'connect_timeout' => (float) env('PSP_CONNECT_TIMEOUT', 1),
        // Where the PSP sends final statuses of pending charges.
        'webhook_url' => env('PSP_WEBHOOK_URL', 'http://web/api/webhooks/psp'),
        // HMAC-SHA256 over "timestamp.body". A demo value: set your own outside this demo.
        'webhook_secret' => env('PSP_WEBHOOK_SECRET', 'demo-webhook-secret'),
        // Seconds a signed webhook stays valid (replay protection).
        'webhook_tolerance' => (int) env('PSP_WEBHOOK_TOLERANCE', 300),
        // payments:reconcile asks the PSP about payments processing for longer than this.
        'reconcile_after' => (int) env('PSP_RECONCILE_AFTER', 60),
    ],

];
