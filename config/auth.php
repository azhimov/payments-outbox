<?php

declare(strict_types=1);

/*
| The API has no user accounts: merchants authenticate with an X-Api-Key header
| (App\Presentation\Http\Middleware\AuthenticateMerchant). Laravel still needs
| a guard definition to boot its auth services.
*/

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'merchants',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'merchants',
        ],
    ],

    'providers' => [
        'merchants' => [
            'driver' => 'database',
            'table' => 'merchants',
        ],
    ],

    'passwords' => [],

    'password_timeout' => 10800,

];
