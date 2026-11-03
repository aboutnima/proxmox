<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Facades\Proxmox;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Resources\VirtualMachines\VirtualMachines;

const API_URL = 'https://proxmox.example.test:8006/api2/json';

beforeEach(function () {
    config()->set([
        'proxmox.connections.default.url' => API_URL,
        'proxmox.connections.default.api_token_id' => 'laravel@pam!package',
        'proxmox.connections.default.api_token_secret' => 'token-secret',
    ]);

    Http::preventStrayRequests();
    Carbon::setTestNow('2026-01-01 00:00:00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function () {
    Sleep::fake(false);
    Carbon::setTestNow();
});

it('asks Proxmox for the next free VM ID', function (int|string $data) {
    Http::fake([API_URL.'/cluster/nextid' => Http::response(['data' => $data])]);

    expect(Proxmox::nextVirtualMachineId())->toBe(105);
})->with([105, '105']);

it('rejects malformed next VM ID responses', function (mixed $data) {
    Http::fake(['*' => Http::response(['data' => $data])]);

    expect(fn () => Proxmox::nextVirtualMachineId())->toThrow(UnexpectedResponseException::class, 'VM ID');
})->with([null, '', '0', 'abc', [[101]], 1.5]);

it('reads the configuration of a VM', function () {
    Http::fake([
        API_URL.'/nodes/pve1/qemu/101/status/current' => Http::response(['data' => ['vmid' => 101, 'status' => 'running']]),
        API_URL.'/nodes/pve1/qemu/101/config' => Http::response(['data' => ['cores' => 2, 'memory' => '2048']]),
    ]);

    $vm = Proxmox::node('pve1')->virtualMachine(101);

    expect($vm->config())->toBe(['cores' => 2, 'memory' => '2048'])
        ->and($vm->toArray())->toBe(['vmid' => 101, 'status' => 'running']);

    Http::assertSentCount(2);
});

it('resizes a VM disk and returns the resize task', function (string $size) {
    Http::fake([
        '*/status/current' => Http::response(['data' => ['vmid' => 101, 'status' => 'running']]),
        '*/resize' => Http::response(['data' => 'UPID:pve1:resize']),
    ]);

    $vm = Proxmox::node('pve1')->virtualMachine(101);
    $task = $vm->resize('scsi0', $size);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->node)->toBe('pve1')
        ->and($task->upid)->toBe('UPID:pve1:resize')
        ->and($vm->toArray())->toBe(['vmid' => 101, 'status' => 'running']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === API_URL.'/nodes/pve1/qemu/101/resize'
        && $request->data() === ['disk' => 'scsi0', 'size' => $size]);
    Http::assertSentCount(2);
})->with(['32G', '+10G', '1.5T', '2048M']);

it('validates disk resize arguments before sending a resize request', function (string $disk, string $size, string $message) {
    Http::fake(['*/status/current' => Http::response(['data' => ['status' => 'running']])]);

    $vm = Proxmox::node('pve1')->virtualMachine(101);

    expect(fn () => $vm->resize($disk, $size))
        ->toThrow(InvalidArgumentException::class, $message);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
})->with([
    ['scsi', '10G', 'disk'],
    ['scsi0/../x', '10G', 'disk'],
    ['scsi0', '-10G', 'size'],
    ['scsi0', '10GB', 'size'],
    ['scsi0', '', 'size'],
]);

it('creates a VM from an array through its node-scoped operations', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:pve1:create'])]);

    $virtualMachines = Proxmox::node('pve1')->virtualMachines();

    Http::assertNothingSent();

    $task = $virtualMachines->create([
        'vmid' => 101,
        'name' => 'web-1',
        'cores' => 2,
        'sockets' => 1,
        'memory' => 2048,
        'storage' => 'local-lvm',
        'disk' => 20,
        'bridge' => 'vmbr0',
        'ip' => '192.0.2.10',
        'prefix' => 24,
        'gateway' => '192.0.2.1',
        'user' => 'ubuntu',
        'password' => 'secret',
        'ssh_key' => "ssh-ed25519 AAAA first\nssh-ed25519 BBBB second",
        'ostype' => 'l26',
        'onboot' => 1,
        'start' => true,
    ]);

    expect($task->upid)->toBe('UPID:pve1:create');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === API_URL.'/nodes/pve1/qemu'
        && str_contains($request->body(), 'start=1')
        && $request->data() === [
            'vmid' => 101,
            'name' => 'web-1',
            'cores' => 2,
            'sockets' => 1,
            'memory' => 2048,
            'storage' => 'local-lvm',
            'ostype' => 'l26',
            'onboot' => 1,
            'start' => true,
            'scsi0' => 'local-lvm:20',
            'scsihw' => 'virtio-scsi-pci',
            'net0' => 'virtio,bridge=vmbr0',
            'ipconfig0' => 'ip=192.0.2.10/24,gw=192.0.2.1',
            'ciuser' => 'ubuntu',
            'cipassword' => 'secret',
            'sshkeys' => rawurlencode("ssh-ed25519 AAAA first\nssh-ed25519 BBBB second"),
            'ide2' => 'local-lvm:cloudinit',
        ]);
});

it('accepts integer or string VM IDs when creating a VM', function (int|string $vmid) {
    Http::fake(['*' => Http::response(['data' => 'UPID:pve1:create'])]);

    $task = Proxmox::node('pve1')->virtualMachines()->create(['vmid' => $vmid]);

    expect($task->node)->toBe('pve1')
        ->and($task->upid)->toBe('UPID:pve1:create');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['vmid'] === $vmid);
    Http::assertSentCount(1);
})->with([100, '101', 999999999, '999999999']);

it('creates a VM using the exact static IP settings in its array', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:pve1:create'])]);

    Proxmox::node('pve1')->virtualMachines()->create([
        'vmid' => 101,
        'storage' => 'local-lvm',
        'bridge' => 'vmbr0',
        'ip' => '192.0.2.20',
        'prefix' => 25,
    ]);

    Http::assertSent(fn (Request $request): bool => $request['ipconfig0'] === 'ip=192.0.2.20/25');
    Http::assertSentCount(1);
});

it('rejects invalid VM IDs before submitting creation', function (mixed $vmid) {
    expect(fn () => Proxmox::node('pve1')->virtualMachines()->create(['vmid' => $vmid]))
        ->toThrow(InvalidArgumentException::class, 'VM ID');

    Http::assertNothingSent();
})->with([0, 99, 1_000_000_000, '0101', 'abc', null, '', 1.5]);

it('requires a VM ID before submitting creation', function () {
    expect(fn () => Proxmox::node('pve1')->virtualMachines()->create([]))
        ->toThrow(InvalidArgumentException::class, 'VM ID');

    Http::assertNothingSent();
});

it('keeps creation on VM operations without query methods or node aliases', function () {
    $node = Proxmox::node('pve1');
    $virtualMachines = $node->virtualMachines();

    foreach (['where', 'first', 'get', 'make'] as $method) {
        expect(method_exists($virtualMachines, $method))->toBeFalse();
    }

    foreach (['createVirtualMachine', 'makeVirtualMachine'] as $method) {
        expect(method_exists($node, $method))->toBeFalse();
    }

    Http::assertNothingSent();
});

it('imports, waits and resizes a VM created from array configuration', function () {
    Http::fake([
        API_URL.'/nodes/pve1/qemu' => Http::response(['data' => 'UPID:pve1:import']),
        API_URL.'/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        API_URL.'/nodes/pve1/qemu/101/resize' => Http::response(['data' => 'UPID:pve1:resize']),
    ]);

    $task = Proxmox::node('pve1')->virtualMachines()->create([
        'vmid' => 101,
        'storage' => 'local-lvm',
        'image' => 'local:import/ubuntu.qcow2',
        'disk' => 32,
        'bridge' => 'vmbr0',
        'ip' => 'dhcp',
    ], importTimeout: 60);

    expect($task->upid)->toBe('UPID:pve1:resize');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'POST',
        fn (Request $request): bool => $request->method() === 'GET',
        fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['disk' => 'scsi0', 'size' => '32G'],
    ]);
});

it('reuses node-scoped VM operations for listing and creation', function () {
    Http::fake(['*/qemu' => Http::sequence()
        ->push(['data' => [['vmid' => 101]]])
        ->push(['data' => 'UPID:pve1:create'])
        ->push(['data' => [['vmid' => 101], ['vmid' => 102]]]),
    ]);

    $virtualMachines = Proxmox::node('pve1')->virtualMachines();

    expect($virtualMachines)->toBeInstanceOf(VirtualMachines::class)
        ->and($virtualMachines->node)->toBe('pve1');

    Http::assertNothingSent();

    $original = $virtualMachines->all();
    $task = $virtualMachines->create(['vmid' => 102]);

    expect($task->upid)->toBe('UPID:pve1:create')
        ->and($virtualMachines->all())->toBe([['vmid' => 101], ['vmid' => 102]])
        ->and($original)->toBe([['vmid' => 101]]);

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'GET',
        fn (Request $request): bool => $request->method() === 'POST',
        fn (Request $request): bool => $request->method() === 'GET',
    ]);
});

it('supports consumer collection operations on a single fetched list', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online', 'maxmem' => 8],
        ['node' => 'pve2', 'status' => 'offline', 'maxmem' => 16],
        ['node' => 'pve3', 'status' => 'online', 'maxmem' => 32],
    ]])]);

    $nodes = collect(Proxmox::nodes());

    expect($nodes->count())->toBe(3)
        ->and($nodes->pluck('node')->all())->toBe(['pve1', 'pve2', 'pve3'])
        ->and($nodes->sum('maxmem'))->toBe(56)
        ->and($nodes->where('status', 'online')->count())->toBe(2)
        ->and($nodes->isNotEmpty())->toBeTrue()
        ->and($nodes->sortByDesc('maxmem')->first()['node'])->toBe('pve3')
        ->and($nodes->keyBy('node')->keys()->all())->toBe(['pve1', 'pve2', 'pve3']);

    Http::assertSentCount(1);
});

it('leaves membership filtering to consumer collections', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online'],
        ['node' => 'pve2', 'status' => 'offline'],
        ['node' => 'pve3', 'status' => 'unknown'],
    ]])]);

    $nodes = collect(Proxmox::nodes());

    expect($nodes->whereIn('status', ['online', 'unknown'])->pluck('node')->all())->toBe(['pve1', 'pve3'])
        ->and($nodes->whereNotIn('status', ['online'])->pluck('node')->all())->toBe(['pve2', 'pve3']);

    Http::assertSentCount(1);
});

it('leaves conditional filtering to consumer collections', function (bool $onlineOnly, array $expected) {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online'],
        ['node' => 'pve2', 'status' => 'offline'],
    ]])]);

    $nodes = collect(Proxmox::nodes())
        ->when($onlineOnly, fn (Collection $items) => $items->where('status', 'online'));

    expect($nodes->pluck('node')->all())->toBe($expected);
    Http::assertSentCount(1);
})->with([
    [true, ['pve1']],
    [false, ['pve1', 'pve2']],
]);

it('returns arrays that consumers can filter with native PHP', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online'],
        ['node' => 'pve2', 'status' => 'offline'],
    ]])]);

    $nodes = Proxmox::nodes();
    $online = array_values(array_filter($nodes, fn (array $node): bool => $node['status'] === 'online'));

    expect($online)->toBe([['node' => 'pve1', 'status' => 'online']])
        ->and($nodes)->toHaveCount(2);

    Http::assertSentCount(1);
});

it('keeps consumer collection changes local', function () {
    Http::fake(['*' => Http::response(['data' => [['node' => 'pve1']]])]);

    $data = Proxmox::nodes();
    $nodes = collect($data);
    $nodes->push(['node' => 'pve9']);

    expect($data)->toBe([['node' => 'pve1']])
        ->and($nodes->all())->toBe([['node' => 'pve1'], ['node' => 'pve9']]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});
