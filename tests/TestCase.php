<?php

declare(strict_types=1);

namespace Proxmox\Proxmox\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Proxmox\Proxmox\ProxmoxServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ProxmoxServiceProvider::class,
        ];
    }
}
