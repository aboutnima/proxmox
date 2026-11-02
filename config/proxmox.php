<?php

declare(strict_types=1);

return [
    'url' => env('PROXMOX_URL', ''),
    'api_token_id' => env('PROXMOX_API_TOKEN_ID', ''),
    'api_token_secret' => env('PROXMOX_API_TOKEN_SECRET', ''),
    'timeout' => env('PROXMOX_TIMEOUT', 30),
    'verify_ssl' => env('PROXMOX_VERIFY_SSL', true),
];
