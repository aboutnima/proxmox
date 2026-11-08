<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Facades\Cluster;
use Proxmox\Models\Cluster as ClusterRecord;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Resources\VirtualMachines\VirtualMachine;

const API_URL = 'https://proxmox.example.test:8006/api2/json';

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    $this->cluster = ClusterRecord::query()->create([
        'name' => 'default',
        'url' => API_URL,
        'api_token_id' => 'laravel@pam!package',
        'api_token_secret' => 'token-secret',
    ]);

    Http::preventStrayRequests();
    Carbon::setTestNow('2026-01-01 00:00:00');
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function () {
    Sleep::fake(false);
    Carbon::setTestNow();
});

it('asks Proxmox for the next free VM ID', function (int|string $data, int $expected) {
    Http::fake([API_URL.'/cluster/nextid' => Http::response(['data' => $data])]);

    expect(Cluster::nextVirtualMachineId())->toBe($expected);
})->with([
    [105, 105],
    ['105', 105],
    [100, 100],
    ['999999999', 999999999],
]);

it('rejects malformed next VM ID responses', function (mixed $data) {
    Http::fake(['*' => Http::response(['data' => $data])]);

    expect(fn () => Cluster::nextVirtualMachineId())->toThrow(UnexpectedResponseException::class, 'VM ID');
})->with([null, '', '0', 'abc', [[101]], 1.5, 99, '99', 1000000000, '1000000000', '999999999999999999999999999999']);

it('reads the configuration of a VM', function () {
    Http::fake([
        API_URL.'/nodes/pve1/qemu/101/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 101]]),
        API_URL.'/nodes/pve1/qemu/101/config' => Http::response(['data' => ['cores' => 2, 'memory' => '2048']]),
    ]);

    $vm = Cluster::node('pve1')->virtualMachine(101)->refresh();

    expect($vm->config())->toBe(['cores' => 2, 'memory' => '2048'])
        ->and($vm->toArray())->toBe(['status' => 'running', 'vmid' => 101]);

    Http::assertSentCount(2);
});

it('grows a VM disk by a number of GiB and returns the resize task', function () {
    Http::fake([
        '*/status/current' => Http::response(['data' => ['status' => 'running', 'vmid' => 101]]),
        '*/resize' => Http::response(['data' => 'UPID:pve1:resize']),
    ]);

    $vm = Cluster::node('pve1')->virtualMachine(101)->refresh();
    $task = $vm->growDisk(disk: 'scsi0', gibibytes: 10);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->node)->toBe('pve1')
        ->and($task->upid)->toBe('UPID:pve1:resize')
        ->and($vm->toArray())->toBe(['status' => 'running', 'vmid' => 101]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === API_URL.'/nodes/pve1/qemu/101/resize'
        && $request->isForm()
        && $request->body() === 'disk=scsi0&size=%2B10G');
    Http::assertSentCount(2);
});

it('validates disk growth arguments before sending a resize request', function (string $disk, int $gibibytes, string $message) {
    Http::fake(['*/status/current' => Http::response(['data' => ['status' => 'running']])]);

    $vm = Cluster::node('pve1')->virtualMachine(101);

    expect(fn () => $vm->growDisk($disk, $gibibytes))
        ->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    'missing disk number' => ['scsi', 10, 'disk must be a disk key'],
    'path traversal' => ['scsi0/../x', 10, 'disk must be a disk key'],
    'zero size' => ['scsi0', 0, 'gibibytes must be a positive integer'],
    'negative size' => ['scsi0', -10, 'gibibytes must be a positive integer'],
]);

it('sets VM CPU cores and sockets and returns the configuration task', function () {
    Http::fake([
        '*/status/current' => Http::response(['data' => ['cpus' => 2, 'status' => 'running', 'vmid' => 101]]),
        '*/config' => Http::response(['data' => 'UPID:pve1:qmconfig']),
    ]);

    $vm = Cluster::node('pve1')->virtualMachine(101)->refresh();
    $task = $vm->setCpu(cores: 4, sockets: 2);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->node)->toBe('pve1')
        ->and($task->upid)->toBe('UPID:pve1:qmconfig')
        ->and($vm->toArray())->toBe(['cpus' => 2, 'status' => 'running', 'vmid' => 101]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === API_URL.'/nodes/pve1/qemu/101/config'
        && $request->isForm()
        && $request->body() === 'cores=4&sockets=2');
    Http::assertSentCount(2);
});

it('keeps the current socket count when only setting VM CPU cores', function () {
    Http::fake([
        '*/status/current' => Http::response(['data' => ['status' => 'stopped']]),
        '*/config' => Http::response(['data' => 'UPID:pve1:qmconfig']),
    ]);

    Cluster::node('pve1')->virtualMachine(101)->setCpu(4);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->body() === 'cores=4');
});

it('sets VM memory in MiB and returns the configuration task', function () {
    Http::fake([
        '*/status/current' => Http::response(['data' => ['status' => 'running']]),
        '*/config' => Http::response(['data' => 'UPID:pve1:qmconfig']),
    ]);

    $task = Cluster::node('pve1')->virtualMachine(101)->setMemory(mebibytes: 8192);

    expect($task->upid)->toBe('UPID:pve1:qmconfig');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === API_URL.'/nodes/pve1/qemu/101/config'
        && $request->isForm()
        && $request->body() === 'memory=8192');
});

it('runs VM power operations and returns their task', function (Closure $operation, string $path, string $body) {
    Http::fake([
        API_URL.'/nodes/pve1/qemu/101/status/current' => Http::response(['data' => ['status' => 'running']]),
        API_URL.'/nodes/pve1/qemu/101/status/'.$path => Http::response(['data' => 'UPID:pve1:power']),
    ]);

    $vm = Cluster::node('pve1')->virtualMachine(101);
    $task = $operation($vm);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->node)->toBe('pve1')
        ->and($task->upid)->toBe('UPID:pve1:power');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === API_URL.'/nodes/pve1/qemu/101/status/'.$path
        && $request->body() === $body);
    Http::assertSentCount(1);
})->with([
    'start' => [fn (VirtualMachine $vm): Task => $vm->start(), 'start', ''],
    'graceful shutdown' => [fn (VirtualMachine $vm): Task => $vm->shutdown(), 'shutdown', ''],
    'restart' => [fn (VirtualMachine $vm): Task => $vm->restart(), 'reboot', ''],
    'pause' => [fn (VirtualMachine $vm): Task => $vm->pause(), 'suspend', 'todisk=0'],
    'hibernate' => [fn (VirtualMachine $vm): Task => $vm->hibernate(), 'suspend', 'todisk=1'],
    'stop' => [fn (VirtualMachine $vm): Task => $vm->stop(), 'stop', ''],
    'reset' => [fn (VirtualMachine $vm): Task => $vm->reset(), 'reset', ''],
    'resume' => [fn (VirtualMachine $vm): Task => $vm->resume(), 'resume', ''],
]);

it('passes an optional timeout to graceful shutdown and restart', function (Closure $operation, string $path) {
    Http::fake([
        API_URL.'/nodes/pve1/qemu/101/status/current' => Http::response(['data' => ['status' => 'running']]),
        API_URL.'/nodes/pve1/qemu/101/status/'.$path => Http::response(['data' => 'UPID:pve1:power']),
    ]);

    $operation(Cluster::node('pve1')->virtualMachine(101));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === API_URL.'/nodes/pve1/qemu/101/status/'.$path
        && $request->body() === 'timeout=30');
})->with([
    'shutdown' => [fn (VirtualMachine $vm): Task => $vm->shutdown(timeout: 30), 'shutdown'],
    'restart' => [fn (VirtualMachine $vm): Task => $vm->restart(timeout: 30), 'reboot'],
]);

it('rejects negative VM operation timeouts before sending the operation', function (Closure $operation) {
    Http::fake(['*/status/current' => Http::response(['data' => ['status' => 'running']])]);

    expect(fn () => $operation(Cluster::node('pve1')->virtualMachine(101)))
        ->toThrow(InvalidArgumentException::class, 'timeout');

    Http::assertNothingSent();
})->with([
    'shutdown' => [fn (VirtualMachine $vm): Task => $vm->shutdown(timeout: -1)],
    'restart' => [fn (VirtualMachine $vm): Task => $vm->restart(timeout: -1)],
]);

it('validates CPU and memory values before sending a configuration request', function (Closure $change, string $message) {
    Http::fake(['*/status/current' => Http::response(['data' => ['status' => 'running']])]);

    $vm = Cluster::node('pve1')->virtualMachine(101);

    expect(fn () => $change($vm))
        ->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    'zero cores' => [fn (VirtualMachine $vm): Task => $vm->setCpu(0), 'cores must be a positive integer'],
    'negative cores' => [fn (VirtualMachine $vm): Task => $vm->setCpu(-2), 'cores must be a positive integer'],
    'zero sockets' => [fn (VirtualMachine $vm): Task => $vm->setCpu(2, 0), 'sockets must be a positive integer'],
    'zero memory' => [fn (VirtualMachine $vm): Task => $vm->setMemory(0), 'mebibytes must be a positive integer'],
    'negative memory' => [fn (VirtualMachine $vm): Task => $vm->setMemory(-1024), 'mebibytes must be a positive integer'],
]);

it('requires a task ID from the VM configuration endpoint', function (mixed $data) {
    Http::fake([
        '*/status/current' => Http::response(['data' => ['status' => 'running']]),
        '*/config' => Http::response(['data' => $data]),
    ]);

    $vm = Cluster::node('pve1')->virtualMachine(101);

    expect(fn () => $vm->setMemory(4096))
        ->toThrow(UnexpectedResponseException::class, 'task ID');
})->with([null, '', [['UPID:pve1:qmconfig']]]);

it('creates a VM from an array through its node-scoped operations', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:pve1:create'])]);

    $node = Cluster::node('pve1');

    Http::assertNothingSent();

    $task = $node->createVirtualMachine([
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

    $task = Cluster::node('pve1')->createVirtualMachine(['vmid' => $vmid]);

    expect($task->node)->toBe('pve1')
        ->and($task->upid)->toBe('UPID:pve1:create');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['vmid'] === $vmid);
    Http::assertSentCount(1);
})->with([100, '101', 999999999, '999999999']);

it('creates a VM using the exact static IP settings in its array', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:pve1:create'])]);

    Cluster::node('pve1')->createVirtualMachine([
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
    expect(fn () => Cluster::node('pve1')->createVirtualMachine(['vmid' => $vmid]))
        ->toThrow(InvalidArgumentException::class, 'VM ID');

    Http::assertNothingSent();
})->with([0, 99, 1_000_000_000, '0101', 'abc', null, '', 1.5]);

it('requires a VM ID before submitting creation', function () {
    expect(fn () => Cluster::node('pve1')->createVirtualMachine([]))
        ->toThrow(InvalidArgumentException::class, 'VM ID');

    Http::assertNothingSent();
});

it('imports, waits and resizes a VM created from array configuration', function () {
    Http::fake([
        API_URL.'/nodes/pve1/qemu' => Http::response(['data' => 'UPID:pve1:import']),
        API_URL.'/nodes/pve1/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        API_URL.'/nodes/pve1/qemu/101/resize' => Http::response(['data' => 'UPID:pve1:resize']),
    ]);

    $task = Cluster::node('pve1')->createVirtualMachine([
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

it('lists and creates VMs through the same node without mutating earlier data', function () {
    Http::fake(['*/qemu' => Http::sequence()
        ->push(['data' => [['vmid' => 101]]])
        ->push(['data' => 'UPID:pve1:create'])
        ->push(['data' => [['vmid' => 101], ['vmid' => 102]]]),
    ]);

    $node = Cluster::node('pve1');
    Http::assertNothingSent();

    $original = $node->virtualMachines();
    $task = $node->createVirtualMachine(['vmid' => 102]);

    expect($task->upid)->toBe('UPID:pve1:create')
        ->and($node->virtualMachines())->toBe([['vmid' => 101], ['vmid' => 102]])
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

    $nodes = collect(Cluster::nodes());

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

    $nodes = collect(Cluster::nodes());

    expect($nodes->whereIn('status', ['online', 'unknown'])->pluck('node')->all())->toBe(['pve1', 'pve3'])
        ->and($nodes->whereNotIn('status', ['online'])->pluck('node')->all())->toBe(['pve2', 'pve3']);

    Http::assertSentCount(1);
});

it('leaves conditional filtering to consumer collections', function (bool $onlineOnly, array $expected) {
    Http::fake(['*' => Http::response(['data' => [
        ['node' => 'pve1', 'status' => 'online'],
        ['node' => 'pve2', 'status' => 'offline'],
    ]])]);

    $nodes = collect(Cluster::nodes())
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

    $nodes = Cluster::nodes();
    $online = array_values(array_filter($nodes, fn (array $node): bool => $node['status'] === 'online'));

    expect($online)->toBe([['node' => 'pve1', 'status' => 'online']])
        ->and($nodes)->toHaveCount(2);

    Http::assertSentCount(1);
});

it('keeps consumer collection changes local', function () {
    Http::fake(['*' => Http::response(['data' => [['node' => 'pve1']]])]);

    $data = Cluster::nodes();
    $nodes = collect($data);
    $nodes->push(['node' => 'pve9']);

    expect($data)->toBe([['node' => 'pve1']])
        ->and($nodes->all())->toBe([['node' => 'pve1'], ['node' => 'pve9']]);

    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->method() !== 'GET');
});
