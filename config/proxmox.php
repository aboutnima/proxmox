<?php

declare(strict_types=1);

return [

    // The name of the stored cluster used when connection() has no name.
    'default' => env('PROXMOX_CONNECTION', 'default'),

];
