<?php

return [
    'apps' => [
        'provider' => 'config',
        'apps' => [
            [
                'app_id' => env('REVERB_APP_ID'),
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'allowed_origins' => ['localhost', '127.0.0.1'],
                'ping_interval' => 60,
                'max_message_size' => 10_000,
            ],
        ],
    ],
];
