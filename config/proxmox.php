<?php

declare(strict_types=1);

use Proxmox\Models\Cluster;

return [

    // The name of the stored cluster used when connection() has no name.
    'default' => env('PROXMOX_CONNECTION', 'default'),

    // Extend this model to customize cluster connection records.
    'model' => Cluster::class,

];
