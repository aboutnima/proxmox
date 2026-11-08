<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Proxmox\Facades\Cluster;
use Proxmox\Models\Cluster as ClusterRecord;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    ClusterRecord::query()->create([
        'name' => 'hetzner',
        'url' => 'https://hetzner.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'hetzner-secret',
    ]);

    Http::preventStrayRequests();
});

it('reads live nodes and virtual machines through a stored cluster connection', function () {
    Http::fake([
        '*/nodes' => Http::response(['data' => [['node' => 'pve1']]]),
        '*/nodes/pve1/qemu' => Http::response(['data' => [['status' => 'running', 'vmid' => 101]]]),
    ]);

    $connection = Cluster::connection('hetzner');

    expect($connection->nodes())->toBe([['node' => 'pve1']])
        ->and($connection->node('pve1')->virtualMachines())->toBe([['status' => 'running', 'vmid' => 101]]);

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pve!app=hetzner-secret'));
    expect(ClusterRecord::query()->count())->toBe(1);
});

it('installs only the cluster connection registry', function () {
    expect(Schema::hasTable('clusters'))->toBeTrue()
        ->and(Schema::hasTable('proxmox_nodes'))->toBeFalse()
        ->and(Schema::hasTable('proxmox_virtual_machines'))->toBeFalse();
});

it('keeps VM creation and power operations independent of application records', function () {
    Http::fake([
        '*/nodes/pve1/qemu' => Http::response(['data' => 'UPID:pve1:create']),
        '*/nodes/pve1/qemu/101/status/start' => Http::response(['data' => 'UPID:pve1:start']),
    ]);

    $node = Cluster::connection('hetzner')->node('pve1');

    expect($node->createVirtualMachine(['vmid' => 101, 'cores' => 2])->upid)->toBe('UPID:pve1:create')
        ->and($node->virtualMachine(101)->start()->upid)->toBe('UPID:pve1:start');

    Http::assertSentCount(2);
    expect(ClusterRecord::query()->count())->toBe(1);
});
