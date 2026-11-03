<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Models\Cluster;
use Proxmox\Models\Node;
use Proxmox\Models\VirtualMachine;
use Proxmox\Resources\Tasks\Task;
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
});

function recordedVirtualMachine(Node $node, int $vmid = 101): VirtualMachine
{
    return VirtualMachine::withoutProxmox(fn () => VirtualMachine::query()->create([
        'cluster_id' => $node->cluster_id,
        'node_id' => $node->id,
        'vmid' => $vmid,
    ]));
}

it('creates the VM on Proxmox and records it for its owner', function () {
    Http::fake([
        INVENTORY_API_URL.'/cluster/nextid' => Http::response(['data' => '120']),
        INVENTORY_API_URL.'/nodes/pve1/qemu' => Http::response(['data' => 'UPID:pve1:create']),
    ]);

    $vm = $this->user->virtualMachines()->create([
        'node' => $this->node,
        'name' => 'web-120',
        'cores' => 2,
        'memory' => 2048,
    ]);

    expect($vm->exists)->toBeTrue()
        ->and($vm->vmid)->toBe(120)
        ->and($vm->name)->toBe('web-120')
        ->and($vm->cluster_id)->toBe($this->cluster->id)
        ->and($vm->node_id)->toBe($this->node->id)
        ->and($vm->owner->is($this->user))->toBeTrue()
        ->and($vm->creationTask())->toBeInstanceOf(Task::class)
        ->and($vm->creationTask()?->upid)->toBe('UPID:pve1:create');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === INVENTORY_API_URL.'/nodes/pve1/qemu'
        && $request->body() === 'name=web-120&cores=2&memory=2048&vmid=120');
});

it('requests another VMID when the chosen one was taken meanwhile', function () {
    Http::fake([
        INVENTORY_API_URL.'/cluster/nextid' => Http::sequence()->push(['data' => '120'])->push(['data' => '121']),
        INVENTORY_API_URL.'/nodes/pve1/qemu' => Http::sequence()
            ->push(['errors' => null, 'message' => "unable to create VM 120 - VM 120 already exists on node 'pve2'"], 500)
            ->push(['data' => 'UPID:pve1:create']),
    ]);

    $vm = $this->user->virtualMachines()->create(['node' => $this->node, 'name' => 'web']);

    expect($vm->vmid)->toBe(121)->and(VirtualMachine::query()->sole()->vmid)->toBe(121);

    Http::assertSentCount(4);
});

it('rejects a VMID passed manually', function () {
    expect(fn () => $this->user->virtualMachines()->create(['node' => $this->node, 'vmid' => 130]))
        ->toThrow(InvalidArgumentException::class, 'Do not pass vmid');

    Http::assertNothingSent();
    expect(VirtualMachine::query()->count())->toBe(0);
});

it('accepts a node name with a cluster model or name', function (Closure $cluster, string $node) {
    Http::fake([
        INVENTORY_API_URL.'/cluster/nextid' => Http::response(['data' => '130']),
        '*' => Http::response(['data' => 'UPID:pve2:create']),
    ]);

    $vm = $this->user->virtualMachines()->create(['cluster' => $cluster($this->cluster), 'node' => $node]);

    expect($vm->node->name)->toBe($node)
        ->and($vm->node->cluster_id)->toBe($this->cluster->id)
        ->and($vm->cluster_id)->toBe($this->cluster->id);

    Http::assertSent(fn (Request $request) => $request->url() === INVENTORY_API_URL."/nodes/{$node}/qemu");
})->with([
    'cluster model, new node' => [fn (Cluster $cluster) => $cluster, 'pve2'],
    'cluster name, existing node' => [fn (Cluster $cluster) => $cluster->name, 'pve1'],
]);

it('does not record the VM when Proxmox rejects it', function () {
    Http::fake([
        '*/cluster/nextid' => Http::response(['data' => '101']),
        '*/nodes/pve1/qemu' => Http::response(['errors' => ['storage' => 'does not exist']], 500),
    ]);

    expect(fn () => $this->user->virtualMachines()->create(['node' => $this->node]))
        ->toThrow(RequestException::class);

    expect(VirtualMachine::withTrashed()->count())->toBe(0);
});

it('requires a node to create the VM on', function (array $attributes, string $message) {
    expect(fn () => $this->user->virtualMachines()->create($attributes))
        ->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    'missing node' => [[], 'Pass the node to create the VM on'],
    'node name without cluster' => [['node' => 'pve1'], 'Pass the cluster together with a node name'],
    'invalid node' => [['node' => 5], 'node must be a Proxmox\Models\Node or a node name.'],
]);

it('rejects an unknown cluster name', function () {
    expect(fn () => $this->user->virtualMachines()->create(['cluster' => 'missing', 'node' => 'pve1']))
        ->toThrow(InvalidConfigurationException::class);
});

it('rejects a node from another cluster', function () {
    $other = Cluster::query()->create([
        'name' => 'us',
        'url' => 'https://us.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'secret',
    ]);

    expect(fn () => VirtualMachine::query()->create(['cluster_id' => $other->id, 'node' => $this->node]))
        ->toThrow(InvalidArgumentException::class, 'does not belong to cluster');
});

it('stops and destroys the VM on Proxmox before deleting the record', function () {
    $vm = recordedVirtualMachine($this->node);

    Http::fake([
        INVENTORY_API_URL.'/nodes/pve1/qemu/101/status/current' => Http::response(['data' => ['status' => 'running']]),
        INVENTORY_API_URL.'/nodes/pve1/qemu/101/status/stop' => Http::response(['data' => 'UPID:pve1:stop']),
        INVENTORY_API_URL.'/nodes/pve1/qemu/101?*' => Http::response(['data' => 'UPID:pve1:destroy']),
        INVENTORY_API_URL.'/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
    ]);

    $vm->delete();

    expect($vm->trashed())->toBeTrue();

    Http::assertSentInOrder([
        fn (Request $request) => $request->url() === INVENTORY_API_URL.'/nodes/pve1/qemu/101/status/current',
        fn (Request $request) => $request->method() === 'POST' && str_ends_with($request->url(), '/qemu/101/status/stop'),
        fn (Request $request) => str_contains($request->url(), 'UPID%3Apve1%3Astop'),
        fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === INVENTORY_API_URL.'/nodes/pve1/qemu/101?purge=1&destroy-unreferenced-disks=1',
        fn (Request $request) => str_contains($request->url(), 'UPID%3Apve1%3Adestroy'),
    ]);
});

it('skips stopping a stopped VM', function () {
    $vm = recordedVirtualMachine($this->node);

    Http::fake([
        '*/status/current' => Http::response(['data' => ['status' => 'stopped']]),
        '*/qemu/101?*' => Http::response(['data' => 'UPID:pve1:destroy']),
        '*/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
    ]);

    $vm->delete();

    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/status/stop'));
    expect($vm->trashed())->toBeTrue();
});

it('deletes the record when the VM no longer exists on Proxmox', function (int $status, string $body) {
    $vm = recordedVirtualMachine($this->node);

    Http::fake(['*/status/current' => Http::response($body, $status)]);

    $vm->delete();

    expect($vm->trashed())->toBeTrue();
    Http::assertSentCount(1);
})->with([
    'not found' => [404, ''],
    'missing configuration' => [500, '{"data":null,"message":"Configuration file \'nodes/pve1/qemu-server/101.conf\' does not exist"}'],
]);

it('keeps the record when Proxmox fails to destroy the VM', function () {
    $vm = recordedVirtualMachine($this->node);

    Http::fake(['*/status/current' => Http::response(['message' => 'permission denied'], 500)]);

    expect(fn () => $vm->delete())->toThrow(RequestException::class);
    expect($vm->fresh()?->trashed())->toBeFalse();
});

it('does not call Proxmox when force deleting a trashed VM', function () {
    $vm = recordedVirtualMachine($this->node);
    VirtualMachine::withoutProxmox(fn () => $vm->delete());

    $vm->forceDelete();

    expect(VirtualMachine::withTrashed()->count())->toBe(0);
    Http::assertNothingSent();
});

it('runs power operations on the live VM', function (string $method, string $path) {
    $vm = recordedVirtualMachine($this->node);

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

it('changes CPU, memory, and disk size on the live VM', function () {
    $vm = recordedVirtualMachine($this->node);

    Http::fake([
        '*/config' => Http::response(['data' => 'UPID:pve1:qmconfig']),
        '*/resize' => Http::response(['data' => 'UPID:pve1:resize']),
    ]);

    expect($vm->setCpu(4, 2)->upid)->toBe('UPID:pve1:qmconfig')
        ->and($vm->setCores(3)->upid)->toBe('UPID:pve1:qmconfig')
        ->and($vm->setMemory(4096)->upid)->toBe('UPID:pve1:qmconfig')
        ->and($vm->growDisk('scsi0', 10)->upid)->toBe('UPID:pve1:resize');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/config') && $request->body() === 'cores=4&sockets=2');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/config') && $request->body() === 'cores=3');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/config') && $request->body() === 'memory=4096');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/resize') && $request->body() === 'disk=scsi0&size=%2B10G');
});

it('updates records without creating VMs again', function () {
    $vm = recordedVirtualMachine($this->node);

    $vm->update(['name' => 'renamed', 'cores' => 8]);

    expect($vm->fresh()?->name)->toBe('renamed');
    Http::assertNothingSent();
});
