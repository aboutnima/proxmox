<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Models\Cluster;
use Proxmox\Models\VirtualMachine;
use Proxmox\Resources\VirtualMachines\VirtualMachine as LegacyVirtualMachineResource;
use Proxmox\Resources\VirtualMachines\VirtualMachineResource;
use Workbench\App\Models\User;

const INVENTORY_API_URL = 'https://eu.example.test:8006/api2/json';

beforeEach(function () {
    $this->loadLaravelMigrations();
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    config()->set('proxmox.mode', 'inventory');

    Http::preventStrayRequests();

    $this->cluster = Cluster::query()->create([
        'name' => 'eu',
        'url' => INVENTORY_API_URL,
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'cluster-secret',
    ]);
    $this->node = $this->cluster->nodes()->create(['name' => 'pve1']);
    $this->user = User::query()->create(['name' => 'Nima', 'email' => 'nima@example.test', 'password' => 'secret']);
    $this->attributes = ['cluster_id' => $this->cluster->id, 'node_id' => $this->node->id, 'vmid' => 101, 'name' => 'web'];
});

it('creates, updates, deletes, restores, and force deletes records without calling Proxmox', function () {
    $record = $this->user->virtualMachines()->create($this->attributes);

    expect($record->exists)->toBeTrue()
        ->and($record->owner->is($this->user))->toBeTrue()
        ->and($record->vmid)->toBe(101);

    $record->update(['name' => 'renamed']);
    expect($record->fresh()?->name)->toBe('renamed');

    $record->delete();
    expect($record->trashed())->toBeTrue();

    $record->restore();
    expect($record->trashed())->toBeFalse();

    $record->forceDelete();
    expect(VirtualMachine::withTrashed()->count())->toBe(0);
    Http::assertNothingSent();
});

it('does not expose API management methods on the Eloquent model', function () {
    foreach (['start', 'stop', 'shutdown', 'restart', 'pause', 'hibernate', 'resume', 'reset', 'setCpu', 'setCores', 'setMemory', 'growDisk', 'api', 'creationTask', 'withoutProxmox'] as $method) {
        expect(method_exists(VirtualMachine::class, $method))->toBeFalse();
    }
});

it('wraps an inventory record without fetching or mutating it', function () {
    $record = $this->user->virtualMachines()->create($this->attributes);
    $attributes = $record->getAttributes();

    $vm = VirtualMachineResource::make($record);
    $client = $record->client();
    $resource = $record->resource();

    expect($vm)->toBeInstanceOf(VirtualMachineResource::class)
        ->and($vm->node)->toBe('pve1')
        ->and($vm->vmid)->toBe(101)
        ->and($client)->toBeInstanceOf(VirtualMachineResource::class)
        ->and($client->node)->toBe('pve1')
        ->and($client->vmid)->toBe(101)
        ->and($resource)->toBeInstanceOf(VirtualMachineResource::class)
        ->and($resource->node)->toBe('pve1')
        ->and($resource->vmid)->toBe(101)
        ->and($vm->toArray())->toBe([])
        ->and($record->getAttributes())->toBe($attributes)
        ->and($record->isDirty())->toBeFalse();

    Http::assertNothingSent();
});

it('keeps the previous VM resource factory compatible', function () {
    $record = $this->user->virtualMachines()->create($this->attributes);
    $resource = LegacyVirtualMachineResource::make($record);

    expect($resource)->toBeInstanceOf(LegacyVirtualMachineResource::class)
        ->and($resource->vmid)->toBe(101);

    Http::assertNothingSent();
});

it('runs power operations on the live VM through its resource', function (string $method, string $path) {
    $record = $this->user->virtualMachines()->create($this->attributes);
    $vm = VirtualMachineResource::make($record);

    Http::fake([INVENTORY_API_URL.'/nodes/pve1/qemu/101/status/'.$path => Http::response(['data' => 'UPID:pve1:power'])]);

    expect($vm->{$method}()->upid)->toBe('UPID:pve1:power');
    Http::assertSentCount(1);
})->with([
    ['start', 'start'],
    ['shutdown', 'shutdown'],
    ['restart', 'reboot'],
    ['pause', 'suspend'],
    ['hibernate', 'suspend'],
    ['resume', 'resume'],
    ['stop', 'stop'],
    ['reset', 'reset'],
]);

it('changes CPU, memory, and disk size through the resource without updating the record', function () {
    $record = $this->user->virtualMachines()->create($this->attributes)->refresh();
    $vm = VirtualMachineResource::make($record);

    Http::fake([
        '*/config' => Http::response(['data' => 'UPID:pve1:qmconfig']),
        '*/resize' => Http::response(['data' => 'UPID:pve1:resize']),
    ]);

    expect($vm->setCpu(4, 2)->upid)->toBe('UPID:pve1:qmconfig')
        ->and($vm->setCores(3)->upid)->toBe('UPID:pve1:qmconfig')
        ->and($vm->setMemory(4096)->upid)->toBe('UPID:pve1:qmconfig')
        ->and($vm->growDisk('scsi0', 10)->upid)->toBe('UPID:pve1:resize')
        ->and($record->fresh()?->getAttributes())->toBe($record->getAttributes());

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/config') && $request->body() === 'cores=4&sockets=2');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/config') && $request->body() === 'cores=3');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/config') && $request->body() === 'memory=4096');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/resize') && $request->body() === 'disk=scsi0&size=%2B10G');
});

it('requires a saved, non-deleted inventory record', function (bool $save, bool $delete) {
    $record = new VirtualMachine($this->attributes);

    if ($save) {
        $record->save();
    }

    if ($delete) {
        $record->delete();
    }

    expect(fn () => VirtualMachineResource::make($record))
        ->toThrow(InvalidArgumentException::class, 'saved, non-deleted');

    Http::assertNothingSent();
})->with([
    'unsaved record' => [false, false],
    'deleted record' => [true, true],
]);

it('rejects a node that belongs to another cluster', function () {
    $other = Cluster::query()->create([
        'name' => 'us',
        'url' => 'https://us.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'secret',
    ]);
    $record = $this->user->virtualMachines()->create([...$this->attributes, 'cluster_id' => $other->id]);

    expect(fn () => VirtualMachineResource::make($record))
        ->toThrow(InvalidConfigurationException::class, 'does not belong to cluster');

    Http::assertNothingSent();
});

it('destroys the remote VM without deleting its inventory record', function () {
    $record = $this->user->virtualMachines()->create($this->attributes);
    $vm = VirtualMachineResource::make($record);

    Http::fake(['*/qemu/101?*' => Http::response(['data' => 'UPID:pve1:destroy'])]);

    expect($vm->delete()->upid)->toBe('UPID:pve1:destroy')
        ->and($record->fresh()?->trashed())->toBeFalse();

    $record->delete();
    expect($record->trashed())->toBeTrue();
    Http::assertSentCount(1);
});

it('surfaces remote failures without changing the inventory record', function () {
    $record = $this->user->virtualMachines()->create($this->attributes);
    $vm = VirtualMachineResource::make($record);

    Http::fake(['*/qemu/101?*' => Http::response(['message' => 'permission denied'], 500)]);

    expect(fn () => $vm->delete())->toThrow(RequestException::class);
    expect($record->fresh()?->trashed())->toBeFalse();
});

it('explicitly provisions a VM before recording it for its owner', function () {
    Http::fake([
        INVENTORY_API_URL.'/cluster/nextid' => Http::response(['data' => '120']),
        INVENTORY_API_URL.'/nodes/pve1/qemu' => Http::response(['data' => 'UPID:pve1:create']),
        INVENTORY_API_URL.'/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
    ]);

    $client = $this->node->cluster->client();
    $vmid = $client->nextVirtualMachineId();
    $client->node($this->node->name)->virtualMachines()->create([
        'name' => 'web-120',
        'cores' => 2,
        'memory' => 2048,
        'vmid' => $vmid,
    ])->wait();

    expect(VirtualMachine::query()->count())->toBe(0);

    $record = $this->user->virtualMachines()->create([...$this->attributes, 'vmid' => $vmid, 'name' => 'web-120']);
    expect(VirtualMachineResource::make($record)->vmid)->toBe(120)
        ->and($record->owner->is($this->user))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === INVENTORY_API_URL.'/nodes/pve1/qemu'
        && $request->body() === 'name=web-120&cores=2&memory=2048&vmid=120');
    Http::assertSentCount(3);
});

it('uses configured custom inventory models when wrapping a VM', function () {
    $custom = new class extends VirtualMachine {};
    config()->set('proxmox.models.virtual_machine', $custom::class);

    $record = $this->user->virtualMachines()->create($this->attributes);

    expect($record)->toBeInstanceOf($custom::class)
        ->and(VirtualMachineResource::make($record)->vmid)->toBe(101);

    Http::assertNothingSent();
});
