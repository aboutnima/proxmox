<?php

declare(strict_types=1);

namespace Proxmox\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Proxmox\ProxmoxServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            ProxmoxServiceProvider::class,
        ];
    }
}
