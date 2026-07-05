<?php

return [
    'api_key' => env('RESEND_API_KEY'),

    'domain' => env('RESEND_DOMAIN'),

    'path' => env('RESEND_PATH', 'resend'),

    'webhook' => [
        'secret' => env('RESEND_WEBHOOK_SECRET'),
        'tolerance' => env('RESEND_WEBHOOK_TOLERANCE', 300),
    ],
];
