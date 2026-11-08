<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Proxmox\Facades\Cluster;
use Proxmox\Models\Cluster as ClusterRecord;
use Proxmox\Proxmox;
use Proxmox\ProxmoxManager;
use Proxmox\ProxmoxServiceProvider;

it('resolves a fresh default client on each container lookup', function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    ClusterRecord::query()->create([
        'name' => 'default',
        'url' => 'https://proxmox.example.test:8006/api2/json',
        'api_token_id' => 'a@pve!app',
        'api_token_secret' => 'test-secret',
    ]);
    $first = app(Proxmox::class);
    $second = app(Proxmox::class);

    expect($first)->toBeInstanceOf(Proxmox::class)
        ->and($second)->not->toBe($first)
        ->and($first->getName())->toBe('default')
        ->and($second->getName())->toBe('default');
});

it('exposes the cluster facade through the connection manager', function () {
    expect(Cluster::getFacadeRoot())->toBe(app(ProxmoxManager::class));
});

it('merges the minimal package configuration', function () {
    expect(config('proxmox'))->toBe([
        'default' => 'default',
        'model' => ClusterRecord::class,
    ]);
});

it('boots without running migrations or requiring a stored connection', function () {
    expect(Schema::hasTable('clusters'))->toBeFalse();

    $this->artisan('about', ['--only' => 'proxmox'])
        ->expectsOutputToContain('Unavailable (run the cluster migration)')
        ->assertSuccessful();
});

it('registers connection setup commands', function () {
    expect(array_keys(Artisan::all()))->toContain('proxmox:install', 'proxmox:create-cluster');
});

it('publishes the cluster migration under the proxmox-migrations tag', function () {
    $paths = ProxmoxServiceProvider::pathsToPublish(ProxmoxServiceProvider::class, 'proxmox-migrations');

    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(__DIR__.'/../../database/migrations')])
        ->and(array_values($paths))->toBe([database_path('migrations')]);
});

it('publishes package configuration under the proxmox-config tag', function () {
    $paths = ProxmoxServiceProvider::pathsToPublish(ProxmoxServiceProvider::class, 'proxmox-config');

    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(__DIR__.'/../../config/proxmox.php')])
        ->and(array_values($paths))->toBe([config_path('proxmox.php')]);
});
