<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Facades\Proxmox;
use Proxmox\Resources\Nodes\Nodes;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Resources\VirtualMachines\PendingVirtualMachine;
use Proxmox\Resources\VirtualMachines\VirtualMachines;
use Proxmox\Support\ResourceQuery;

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
    ResourceQuery::flushMacros();
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
    Http::fake([API_URL.'/nodes/pve1/qemu/101/config' => Http::response(['data' => ['cores' => 2, 'memory' => '2048']])]);

    expect(Proxmox::node('pve1')->virtualMachine(101)->config())->toBe(['cores' => 2, 'memory' => '2048']);
});

it('resizes a VM disk and returns the resize task', function (string $size) {
    Http::fake(['*' => Http::response(['data' => 'UPID:pve1:resize'])]);

    $task = Proxmox::node('pve1')->virtualMachine(101)->resize('scsi0', $size);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->node)->toBe('pve1')
        ->and($task->upid)->toBe('UPID:pve1:resize');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === API_URL.'/nodes/pve1/qemu/101/resize'
        && $request->data() === ['disk' => 'scsi0', 'size' => $size]);
})->with(['32G', '+10G', '1.5T', '2048M']);

it('validates disk resize arguments before sending a request', function (string $disk, string $size, string $message) {
    Http::fake();

    expect(fn () => Proxmox::node('pve1')->virtualMachine(101)->resize($disk, $size))
        ->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    ['scsi', '10G', 'disk'],
    ['scsi0/../x', '10G', 'disk'],
    ['scsi0', '-10G', 'size'],
    ['scsi0', '10GB', 'size'],
    ['scsi0', '', 'size'],
]);

it('creates a VM with a fluent definition', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:pve1:create'])]);

    $task = Proxmox::node('pve1')->virtualMachines()
        ->make(101)
        ->name('web-1')
        ->cores(2)
        ->sockets(1)
        ->memory(2048)
        ->storage('local-lvm')
        ->disk(20)
        ->bridge('vmbr0')
        ->ip('192.0.2.10', 24, '192.0.2.1')
        ->user('ubuntu')
        ->password('secret')
        ->sshKey('ssh-ed25519 AAAA first', 'ssh-ed25519 BBBB second')
        ->with(['ostype' => 'l26', 'onboot' => 1])
        ->start()
        ->create();

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

it('exposes the fluent definition as an array', function () {
    $pending = Proxmox::node('pve1')->virtualMachines()->make('101')
        ->ip('192.0.2.10', 24, '192.0.2.1')
        ->dhcp()
        ->when(false, fn (PendingVirtualMachine $vm) => $vm->cores(8))
        ->unless(false, fn (PendingVirtualMachine $vm) => $vm->memory(4096));

    expect($pending)->toBeInstanceOf(PendingVirtualMachine::class)
        ->and($pending->toArray())->toBe(['vmid' => 101, 'ip' => 'dhcp', 'memory' => 4096]);
});

it('replaces the previous prefix and gateway when the static ip changes', function () {
    $pending = Proxmox::node('pve1')->virtualMachines()->make(101)
        ->ip('192.0.2.10', 24, '192.0.2.1')
        ->ip('192.0.2.20', 25);

    expect($pending->toArray())->toBe(['vmid' => 101, 'ip' => '192.0.2.20', 'prefix' => 25]);
});

it('rejects invalid VM IDs when starting a fluent definition', function (int|string $vmid) {
    expect(fn () => Proxmox::node('pve1')->virtualMachines()->make($vmid))
        ->toThrow(InvalidArgumentException::class, 'VM ID');
})->with([0, 99, 1_000_000_000, '0101', 'abc']);

it('imports, waits and resizes a fluent cloud image definition', function () {
    Http::fake([
        API_URL.'/nodes/pve1/qemu' => Http::response(['data' => 'UPID:pve1:import']),
        API_URL.'/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        API_URL.'/nodes/pve1/qemu/101/resize' => Http::response(['data' => 'UPID:pve1:resize']),
    ]);

    $task = Proxmox::node('pve1')->virtualMachines()->make(101)
        ->storage('local-lvm')
        ->image('local:import/ubuntu.qcow2')
        ->disk(32)
        ->bridge('vmbr0')
        ->dhcp()
        ->create(importTimeout: 60);

    expect($task->upid)->toBe('UPID:pve1:resize');

    Http::assertSentInOrder([
        fn (Request $request): bool => $request->method() === 'POST',
        fn (Request $request): bool => $request->method() === 'GET',
        fn (Request $request): bool => $request->method() === 'PUT' && $request->data() === ['disk' => 'scsi0', 'size' => '32G'],
    ]);
});

it('keeps the subclass when filtering so resource actions stay available', function () {
    $query = Proxmox::node('pve1')->virtualMachines()->where('status', 'running');

    expect($query)->toBeInstanceOf(VirtualMachines::class)
        ->and($query->make(101))->toBeInstanceOf(PendingVirtualMachine::class);
});

it('forwards read-only collection methods to the fetched list', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online', 'maxmem' => 8],
        ['node' => 'pve2', 'status' => 'offline', 'maxmem' => 16],
        ['node' => 'pve3', 'status' => 'online', 'maxmem' => 32],
    ]])]);

    $nodes = Proxmox::nodes();

    expect($nodes->count())->toBe(3)
        ->and($nodes->pluck('node')->all())->toBe(['pve1', 'pve2', 'pve3'])
        ->and($nodes->sum('maxmem'))->toBe(56)
        ->and($nodes->where('status', 'online')->count())->toBe(2)
        ->and($nodes->isNotEmpty())->toBeTrue()
        ->and($nodes->sortByDesc('maxmem')->first()['node'])->toBe('pve3')
        ->and($nodes->keyBy('node')->keys()->all())->toBe(['pve1', 'pve2', 'pve3']);

    Http::assertSentCount(7);
});

it('filters with whereIn and whereNotIn', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online'],
        ['node' => 'pve2', 'status' => 'offline'],
        ['node' => 'pve3', 'status' => 'unknown'],
    ]])]);

    expect(Proxmox::nodes()->whereIn('status', ['online', 'unknown'])->pluck('node')->all())->toBe(['pve1', 'pve3'])
        ->and(Proxmox::nodes()->whereNotIn('status', collect(['online']))->pluck('node')->all())->toBe(['pve2', 'pve3']);
});

it('applies conditional filters', function (bool $onlineOnly, array $expected) {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online'],
        ['node' => 'pve2', 'status' => 'offline'],
    ]])]);

    $nodes = Proxmox::nodes()->when($onlineOnly, fn (Nodes $query) => $query->where('status', 'online'));

    expect($nodes->pluck('node')->all())->toBe($expected);
})->with([
    [true, ['pve1']],
    [false, ['pve1', 'pve2']],
]);

it('supports macros on resource listings', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online'],
        ['node' => 'pve2', 'status' => 'offline'],
    ]])]);

    ResourceQuery::macro('online', function (): ResourceQuery {
        /** @var ResourceQuery $this */
        return $this->where('status', 'online');
    });

    expect(Proxmox::nodes()->online()->pluck('node')->all())->toBe(['pve1']);
});

it('throws for unknown listing methods before sending a request', function () {
    Http::fake();

    expect(fn () => Proxmox::nodes()->push(['node' => 'pve9']))
        ->toThrow(BadMethodCallException::class, 'push');

    Http::assertNothingSent();
});
