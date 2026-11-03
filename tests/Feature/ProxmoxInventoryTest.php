<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Facades\Proxmox;
use Proxmox\Inventory;
use Proxmox\Models\Cluster;
use Proxmox\Models\Node;
use Proxmox\Models\VirtualMachine;
use Proxmox\Resources\VirtualMachines\VirtualMachine as VirtualMachineResource;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->loadLaravelMigrations();
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    config()->set('proxmox.mode', 'inventory');
    config()->set('proxmox.connections.default', [
        'url' => 'https://config.example.test:8006/api2/json',
        'api_token_id' => 'config@pve!app',
        'api_token_secret' => 'config-secret',
    ]);

    Http::preventStrayRequests();
});

function createCluster(array $attributes = []): Cluster
{
    return Cluster::query()->create([
        'name' => 'default',
        'url' => 'https://eu.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'cluster-secret',
        ...$attributes,
    ]);
}

it('defaults to api mode and rejects unknown modes', function () {
    config()->set('proxmox.mode', null);
    expect(Inventory::mode())->toBe('api');

    config()->set('proxmox.mode', '');
    expect(Inventory::mode())->toBe('api');

    config()->set('proxmox.mode', 'database');
    expect(fn () => Inventory::enabled())
        ->toThrow(InvalidConfigurationException::class, 'must be [api] or [inventory], [database] given');
});

it('resolves connections from database clusters instead of config', function () {
    createCluster(['name' => 'eu', 'timeout' => 15, 'retry_times' => 2]);
    createCluster();

    Http::fake(['*' => Http::response(['data' => [['node' => 'pve1']]])]);

    $client = Proxmox::connection('eu');

    expect($client->getName())->toBe('eu')
        ->and($client->__debugInfo())->toMatchArray(['timeout' => 15, 'retries' => 2])
        ->and(Proxmox::connection('eu'))->toBe($client)
        ->and(collect($client->nodes())->pluck('node')->all())->toBe(['pve1']);

    Proxmox::nodes();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eu.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pve!app=cluster-secret'));
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'config.example.test'));
});

it('fails clearly when a cluster does not exist', function () {
    expect(fn () => Proxmox::connection('missing'))
        ->toThrow(InvalidConfigurationException::class, 'php artisan proxmox:create-cluster missing');
});

it('encrypts and hides the API token secret', function () {
    $cluster = createCluster();

    $stored = DB::table('proxmox_clusters')->value('api_token_secret');

    expect($stored)->not->toBe('cluster-secret')
        ->and(decrypt($stored, false))->toBe('cluster-secret')
        ->and($cluster->fresh()->api_token_secret)->toBe('cluster-secret')
        ->and($cluster->toArray())->not->toHaveKey('api_token_secret')
        ->and($cluster->toJson())->not->toContain('cluster-secret');
});

it('applies defaults and exposes the connection config', function () {
    $cluster = createCluster(['ca_bundle' => '/etc/pve/pve-root-ca.pem'])->fresh();

    expect($cluster->toConnectionConfig())->toBe([
        'url' => 'https://eu.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'cluster-secret',
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify_ssl' => true,
        'ca_bundle' => '/etc/pve/pve-root-ca.pem',
        'retry' => ['times' => 0, 'sleep' => 100],
    ])->and($cluster->is_active)->toBeTrue()
        ->and(Cluster::query()->active()->count())->toBe(1);
});

it('rebuilds the cached client when a cluster changes', function () {
    $cluster = createCluster();
    $first = $cluster->client();

    expect(Proxmox::connection())->toBe($first);

    $cluster->update(['url' => 'https://new.example.test:8006/api2/json']);

    $second = Proxmox::connection();

    expect($second)->not->toBe($first)
        ->and($second->__debugInfo()['baseUrl'])->toBe('https://new.example.test:8006/api2/json');

    $cluster->update(['name' => 'renamed']);

    expect(Proxmox::getConnections())->not->toHaveKey('default')
        ->and(fn () => Proxmox::connection())->toThrow(InvalidConfigurationException::class);

    Proxmox::connection('renamed');
    $cluster->delete();

    expect(Proxmox::getConnections())->not->toHaveKey('renamed');
});

it('relates clusters, nodes, virtual machines, and owners', function () {
    $cluster = createCluster();
    $node = $cluster->nodes()->create(['name' => 'pve1', 'status' => 'online']);
    $user = User::query()->create(['name' => 'Nima', 'email' => 'nima@example.test', 'password' => 'secret']);

    $vm = new VirtualMachine(['cluster_id' => $cluster->id, 'node_id' => $node->id, 'vmid' => 101, 'name' => 'web']);
    VirtualMachine::withoutProxmox(fn () => $vm->owner()->associate($user)->save());

    expect($user->virtualMachines()->sole()->is($vm))->toBeTrue()
        ->and($vm->owner->is($user))->toBeTrue()
        ->and($vm->cluster->is($cluster))->toBeTrue()
        ->and($vm->node->is($node))->toBeTrue()
        ->and($node->cluster->is($cluster))->toBeTrue()
        ->and($node->virtualMachines()->sole()->is($vm))->toBeTrue()
        ->and($cluster->virtualMachines()->sole()->is($vm))->toBeTrue()
        ->and($node->resource()->name)->toBe('pve1');
});

it('keeps one inventory row per VM lifecycle when a VMID is reused', function () {
    $cluster = createCluster();

    [$first, $second] = VirtualMachine::withoutProxmox(function () use ($cluster) {
        $first = $cluster->virtualMachines()->create(['vmid' => 105]);
        $first->delete();

        return [$first, $cluster->virtualMachines()->create(['vmid' => 105])];
    });

    expect($second->id)->not->toBe($first->id)
        ->and(VirtualMachine::query()->count())->toBe(1)
        ->and(VirtualMachine::withTrashed()->where('vmid', 105)->count())->toBe(2);
});

it('fetches the Proxmox resource for an inventory virtual machine', function () {
    $cluster = createCluster();
    $node = $cluster->nodes()->create(['name' => 'pve1']);
    $vm = VirtualMachine::withoutProxmox(fn () => $cluster->virtualMachines()->create(['node_id' => $node->id, 'vmid' => 101]));

    Http::fake(['*/nodes/pve1/qemu/101/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 101]])]);

    expect($vm->resource())->toBeInstanceOf(VirtualMachineResource::class)
        ->and($vm->resource()->toArray()['status'])->toBe('running');

    $vm->update(['node_id' => null]);

    expect(fn () => $vm->refresh()->resource())
        ->toThrow(InvalidConfigurationException::class, 'has no node in the inventory');
});

it('uses custom inventory models from config', function () {
    $custom = new class extends Node {};
    config()->set('proxmox.models.node', $custom::class);

    $cluster = createCluster();
    $cluster->nodes()->create(['name' => 'pve1']);

    expect($cluster->nodes()->first())->toBeInstanceOf($custom::class);

    config()->set('proxmox.models.node', stdClass::class);

    expect(fn () => $cluster->nodes())
        ->toThrow(InvalidConfigurationException::class, 'proxmox.models.node must be a class that extends');
});

it('lists database clusters in the about command', function () {
    createCluster(['name' => 'us']);
    createCluster(['name' => 'eu']);

    $this->artisan('about', ['--only' => 'proxmox'])
        ->expectsOutputToContain('inventory')
        ->expectsOutputToContain('eu, us')
        ->doesntExpectOutputToContain('cluster-secret')
        ->assertSuccessful();
});
