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

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}
