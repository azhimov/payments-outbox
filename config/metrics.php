<?php

declare(strict_types=1);

return [
    // redis: shared by all php-fpm workers and CLI processes (the default in compose);
    // memory: per process, for tests.
    'storage' => env('METRICS_STORAGE', 'redis'),
    'namespace' => 'payments',
];
