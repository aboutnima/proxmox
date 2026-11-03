<?php

declare(strict_types=1);
use Proxmox\Models\Cluster;
use Proxmox\Models\Node;
use Proxmox\Models\VirtualMachine;

return [

    /*
    |--------------------------------------------------------------------------
    | Package Mode
    |--------------------------------------------------------------------------
    |
    | "api" reads connections from this file and needs no database.
    | "inventory" reads clusters from the proxmox_clusters table and enables
    | the inventory models. Run "php artisan proxmox:install" to switch.
    |
    */

    'mode' => env('PROXMOX_MODE', 'api'),

    /*
    |--------------------------------------------------------------------------
    | Default Proxmox Connection
    |--------------------------------------------------------------------------
    |
    | The connection used by the Proxmox facade and by resolving the
    | Proxmox\Proxmox class. Use Proxmox::connection('name') for others.
    | In inventory mode this is the name of a cluster in the database.
    |
    */

    'default' => env('PROXMOX_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Proxmox Connections
    |--------------------------------------------------------------------------
    |
    | Used in api mode only. Each connection authenticates with an API token.
    | GET requests are retried "retry.times" extra times on connection
    | failures only. "ca_bundle" is a CA file path or PEM certificate.
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
            'ca_bundle' => env('PROXMOX_CA_BUNDLE'),
            'retry' => [
                'times' => (int) env('PROXMOX_RETRY_TIMES', 0),
                'sleep' => (int) env('PROXMOX_RETRY_SLEEP', 100),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Inventory Models
    |--------------------------------------------------------------------------
    |
    | Used in inventory mode. Extend the package models and register them
    | here to add relations or behavior in your application.
    |
    */

    'models' => [
        'cluster' => Cluster::class,
        'node' => Node::class,
        'virtual_machine' => VirtualMachine::class,
    ],

];
