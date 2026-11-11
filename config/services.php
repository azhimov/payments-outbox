<?php

declare(strict_types=1);

return [

    // The payment service provider the API charges through (the fake one in this demo).
    'psp' => [
        'base_url' => env('PSP_BASE_URL', 'http://fake-psp:8000'),
        // Seconds. A slower answer counts as "outcome unknown": the payment stays processing.
        'timeout' => (float) env('PSP_TIMEOUT', 3),
        'connect_timeout' => (float) env('PSP_CONNECT_TIMEOUT', 1),
    ],

];
