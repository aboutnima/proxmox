<?php

declare(strict_types=1);

namespace Proxmox\Tests\Fixtures;

use Proxmox\Models\Cluster;

class CustomCluster extends Cluster
{
    protected $table = 'custom_proxmox_clusters';
}
