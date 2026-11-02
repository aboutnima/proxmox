<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default Proxmox Connection
    |--------------------------------------------------------------------------
    |
    | The connection used by the Proxmox facade and by resolving the
    | Proxmox\Proxmox class. Use Proxmox::connection('name') for others.
    |
    */

    'default' => env('PROXMOX_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Proxmox Connections
    |--------------------------------------------------------------------------
    |
    | Each connection authenticates with an API token. GET requests are
    | retried "retry.times" extra times on connection failures only.
    |
    */

    'connections' => [

        'default' => [
            'url' => env('PROXMOX_URL', ''),
            'api_token_id' => env('PROXMOX_API_TOKEN_ID', ''),
            'api_token_secret' => env('PROXMOX_API_TOKEN_SECRET', ''),
            'timeout' => (int) env('PROXMOX_TIMEOUT', 30),
            'connect_timeout' => (int) env('PROXMOX_CONNECT_TIMEOUT', 10),
            'verify_ssl' => (bool) env('PROXMOX_VERIFY_SSL', true),
            'retry' => [
                'times' => (int) env('PROXMOX_RETRY_TIMES', 0),
                'sleep' => (int) env('PROXMOX_RETRY_SLEEP', 100),
            ],
        ],

    ],

];
