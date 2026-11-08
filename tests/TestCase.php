<?php

declare(strict_types=1);

namespace Proxmox\Tests;

use Illuminate\Filesystem\Filesystem;
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

    protected function getPackageAliases($app): array
    {
        return (new Filesystem)->json(__DIR__.'/../composer.json')['extra']['laravel']['aliases'];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}
