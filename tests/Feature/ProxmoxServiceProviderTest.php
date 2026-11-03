<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Proxmox\Models\Cluster;
use Proxmox\Models\Node;
use Proxmox\Models\VirtualMachine;
use Proxmox\Proxmox;
use Proxmox\ProxmoxManager;
use Proxmox\ProxmoxServiceProvider;

it('resolves the default connection', function () {
    expect(app(Proxmox::class))->toBeInstanceOf(Proxmox::class)
        ->and(app(Proxmox::class)->getName())->toBe('default');
});

it('returns the same instance from the container', function () {
    expect(app(Proxmox::class))->toBe(app(Proxmox::class))
        ->and(app(ProxmoxManager::class))->toBe(app(ProxmoxManager::class));
});

it('exposes the provider and facade under a single package namespace', function () {
    expect(class_exists(ProxmoxServiceProvider::class))->toBeTrue()
        ->and(\Proxmox\Facades\Proxmox::getFacadeRoot())->toBe(app(ProxmoxManager::class))
        ->and(\Proxmox\Facades\Proxmox::connection())->toBe(app(Proxmox::class));
});

it('merges the package config', function () {
    expect(config('proxmox.mode'))->toBe('api')
        ->and(config('proxmox.default'))->toBe('default')
        ->and(config('proxmox.connections.default'))->toBe([
            'url' => '',
            'api_token_id' => '',
            'api_token_secret' => '',
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify_ssl' => true,
            'ca_bundle' => null,
            'retry' => ['times' => 0, 'sleep' => 100],
        ])
        ->and(config('proxmox.models'))->toBe([
            'cluster' => Cluster::class,
            'node' => Node::class,
            'virtual_machine' => VirtualMachine::class,
        ]);
});

it('registers the package commands', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('proxmox:install', 'proxmox:create-cluster', 'proxmox:sync-nodes');
});

it('publishes the inventory migrations under the proxmox-migrations tag', function () {
    $paths = ProxmoxServiceProvider::pathsToPublish(ProxmoxServiceProvider::class, 'proxmox-migrations');

    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(__DIR__.'/../../database/migrations')])
        ->and(array_values($paths))->toBe([database_path('migrations')]);
});
