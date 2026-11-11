<?php

declare(strict_types=1);

return [
    // API key of the seeded fictional "Demo Store" merchant. Public on purpose: it opens only this local demo.
    'api_key' => env('DEMO_API_KEY', 'demo-store-key'),

    // Where demo:load sends its traffic (inside compose: the nginx container).
    'base_url' => env('DEMO_BASE_URL', 'http://web'),
];
