<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Facades\Proxmox;
use Proxmox\Resources\VirtualMachines\VirtualMachine;

const USAGE_MIB = 1024 ** 2;
const USAGE_GIB = 1024 ** 3;

beforeEach(function () {
    config()->set([
        'proxmox.connections.default.url' => 'https://proxmox.example.test:8006/api2/json',
        'proxmox.connections.default.api_token_id' => 'laravel@pam!package',
        'proxmox.connections.default.api_token_secret' => 'token-secret',
    ]);

    Http::preventStrayRequests();
});

function usageVirtualMachine(): VirtualMachine
{
    return new VirtualMachine(Proxmox::connection(), 'my', 101);
}

function fakeVirtualMachineConfig(array $config): void
{
    Http::fake(['*/nodes/my/qemu/101/config*' => Http::response(['data' => $config])]);
}

it('categorizes live VM usage from the current status in MiB and bytes', function () {
    Http::fake(['*/nodes/my/qemu/101/status/current' => Http::response(['data' => [
        'cpu' => 0.12345,
        'cpus' => 4,
        'diskread' => 1000,
        'diskwrite' => 2000,
        'maxmem' => 4096 * USAGE_MIB,
        'mem' => 1024 * USAGE_MIB + 10,
        'netin' => 300,
        'netout' => 400,
        'status' => 'running',
        'uptime' => 3600,
    ]])]);

    expect(usageVirtualMachine()->usage())->toBe([
        'status' => 'running',
        'uptime' => 3600,
        'cpu' => ['vcpus' => 4, 'percent' => 12.35],
        'memory' => ['total' => 4096, 'used' => 1024, 'free' => 3072],
        'network' => ['in' => 300, 'out' => 400],
        'disk' => ['read' => 1000, 'write' => 2000],
    ]);

    Http::assertSentCount(1);
});

it('reports zero usage for a stopped VM without runtime counters', function () {
    Http::fake(['*/nodes/my/qemu/101/status/current' => Http::response(['data' => ['maxmem' => 2048 * USAGE_MIB, 'status' => 'stopped']])]);

    expect(usageVirtualMachine()->usage())->toBe([
        'status' => 'stopped',
        'uptime' => 0,
        'cpu' => ['vcpus' => 0, 'percent' => 0.0],
        'memory' => ['total' => 2048, 'used' => 0, 'free' => 2048],
        'network' => ['in' => 0, 'out' => 0],
        'disk' => ['read' => 0, 'write' => 0],
    ]);
});

it('categorizes the applied VM allocation and skips CD-ROM and Cloud-Init drives', function () {
    fakeVirtualMachineConfig([
        'balloon' => 1024,
        'cores' => 2,
        'ide0' => 'local:iso/ubuntu.iso,media=cdrom,size=2G',
        'ide2' => 'local-lvm:vm-101-cloudinit,media=cdrom',
        'memory' => '4096',
        'sata0' => 'none,media=cdrom',
        'scsi0' => 'local-lvm:vm-101-disk-0,iothread=1,size=32G',
        'scsi10' => 'local-lvm:vm-101-disk-2,size=1T',
        'scsi2' => 'ceph:vm-101-disk-1,size=10240M',
        'sockets' => 2,
        'virtio0' => '/dev/disk/by-id/ata-disk,size=2147483648',
    ]);

    expect(usageVirtualMachine()->resources())->toBe([
        'cpu' => ['sockets' => 2, 'cores' => 2, 'vcpus' => 4],
        'memory' => ['size' => 4096, 'balloon' => 1024],
        'disks' => [
            'scsi0' => ['storage' => 'local-lvm', 'size' => 32],
            'scsi2' => ['storage' => 'ceph', 'size' => 10],
            'scsi10' => ['storage' => 'local-lvm', 'size' => 1024],
            'virtio0' => ['storage' => null, 'size' => 2],
        ],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu/101/config?current=1');
    Http::assertSentCount(1);
});

it('applies Proxmox defaults and reads vCPU limits and the Proxmox 9 memory format', function (array $config, array $expected) {
    fakeVirtualMachineConfig($config);

    expect(usageVirtualMachine()->resources())->toBe($expected);
})->with([
    'defaults' => [
        ['digest' => 'abc'],
        ['cpu' => ['sockets' => 1, 'cores' => 1, 'vcpus' => 1], 'memory' => ['size' => 512, 'balloon' => null], 'disks' => []],
    ],
    'vcpus limit, property memory, disabled balloon' => [
        ['balloon' => 0, 'cores' => 4, 'memory' => 'current=8192', 'vcpus' => 2],
        ['cpu' => ['sockets' => 1, 'cores' => 4, 'vcpus' => 2], 'memory' => ['size' => 8192, 'balloon' => null], 'disks' => []],
    ],
]);

it('summarizes node capacity, usage, and allocation across non-template VMs', function () {
    Http::fake([
        '*/nodes/my/status' => Http::response(['data' => [
            'cpu' => 0.25,
            'cpuinfo' => ['cores' => 8, 'cpus' => 16, 'sockets' => 1],
            'memory' => ['free' => 40960 * USAGE_MIB, 'total' => 65536 * USAGE_MIB, 'used' => 24576 * USAGE_MIB],
        ]]),
        '*/nodes/my/qemu' => Http::response(['data' => [
            ['cpus' => 4, 'maxmem' => 8192 * USAGE_MIB, 'vmid' => 101],
            ['cpus' => 2, 'maxmem' => 4096 * USAGE_MIB, 'vmid' => 102],
            ['cpus' => 8, 'maxmem' => 16384 * USAGE_MIB, 'template' => 1, 'vmid' => 900],
        ]]),
    ]);

    expect(Proxmox::node('my')->resources())->toBe([
        'cpu' => ['cores' => 16, 'percent' => 25.0, 'allocated' => 6],
        'memory' => ['total' => 65536, 'used' => 24576, 'free' => 40960, 'allocated' => 12288],
    ]);

    Http::assertSentCount(2);
});

it('lists enabled node storages keyed by name in GiB', function () {
    Http::fake(['*/nodes/my/storage*' => Http::response(['data' => [
        ['active' => 1, 'avail' => 400 * USAGE_GIB + 5, 'content' => 'images,rootdir', 'shared' => 0, 'storage' => 'local-lvm', 'total' => 500 * USAGE_GIB, 'type' => 'lvmthin', 'used' => 100 * USAGE_GIB],
        ['active' => 0, 'content' => 'images', 'enabled' => 1, 'shared' => 1, 'storage' => 'nfs', 'type' => 'nfs'],
    ]])]);

    expect(Proxmox::node('my')->storages('images'))->toBe([
        'local-lvm' => ['type' => 'lvmthin', 'content' => ['images', 'rootdir'], 'shared' => false, 'active' => true, 'total' => 500, 'used' => 100, 'available' => 400],
        'nfs' => ['type' => 'nfs', 'content' => ['images'], 'shared' => true, 'active' => false, 'total' => 0, 'used' => 0, 'available' => 0],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/storage?enabled=1&content=images');
});

it('lists all enabled storages without a content filter', function () {
    Http::fake(['*/nodes/my/storage*' => Http::response(['data' => []])]);

    expect(Proxmox::node('my')->storages())->toBe([]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/storage?enabled=1');
});

it('rejects invalid storage content types and storage listings without names', function () {
    Http::fake(['*/nodes/my/storage*' => Http::response(['data' => [['type' => 'dir']]])]);

    expect(fn () => Proxmox::node('my')->storages('images&x=1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Proxmox::node('my')->storages())->toThrow(UnexpectedResponseException::class, 'storage name');

    Http::assertSentCount(1);
});

function assertNoNodeCapacityRequests(): void
{
    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/nodes/my/status')
        || str_contains($request->url(), '/nodes/my/storage?')
        || str_contains($request->url(), '/nodes/my/qemu?')
        || str_contains($request->url(), '/cluster/'));
}

function fakeAvailableResources(array $status, array $config = [], array $content = [], ?array $agent = null): void
{
    Http::fake(array_filter([
        '*/nodes/my/qemu/101/status/current' => Http::response(['data' => $status]),
        '*/nodes/my/qemu/101/config*' => Http::response(['data' => $config + ['digest' => 'abc']]),
        '*/nodes/my/storage/local-lvm/content*' => Http::response(['data' => $content]),
        '*/nodes/my/qemu/101/agent/get-fsinfo' => $agent === null ? null : Http::response(...$agent),
    ]));
}

it('limits available resources to the VM allocation, its own volumes, and guest filesystems', function () {
    fakeAvailableResources(
        ['agent' => 1, 'cpu' => 0.25, 'cpus' => 2, 'freemem' => 3072 * USAGE_MIB, 'maxmem' => 4096 * USAGE_MIB, 'mem' => 2048 * USAGE_MIB, 'status' => 'running'],
        ['scsi0' => 'local-lvm:vm-101-disk-0,size=32G', 'virtio0' => '/dev/disk/by-id/ata-disk,size=2G'],
        [['format' => 'raw', 'size' => 32 * USAGE_GIB, 'used' => 6 * USAGE_GIB, 'vmid' => 101, 'volid' => 'local-lvm:vm-101-disk-0']],
        [['data' => ['result' => [
            ['mountpoint' => '/', 'name' => 'sda1', 'total-bytes' => 30 * USAGE_GIB, 'type' => 'ext4', 'used-bytes' => 6 * USAGE_GIB],
            ['mountpoint' => '/proc', 'name' => 'proc', 'type' => 'proc'],
        ]]]],
    );

    expect(usageVirtualMachine()->availableResources())->toBe([
        'cpu' => ['vcpus' => 2, 'used' => 25.0, 'available' => 75.0],
        'memory' => ['total' => 4096, 'used' => 1024, 'available' => 3072],
        'disks' => [
            'scsi0' => ['storage' => 'local-lvm', 'total' => 32, 'used' => 6, 'available' => 26],
            'virtio0' => ['storage' => null, 'total' => 2, 'used' => null, 'available' => null],
        ],
        'filesystems' => [
            ['mountpoint' => '/', 'type' => 'ext4', 'total' => 30, 'used' => 6, 'available' => 24],
        ],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/storage/local-lvm/content?vmid=101');
    assertNoNodeCapacityRequests();
    Http::assertSentCount(4);
});

it('reports disk usage for a stopped VM without asking the guest agent', function () {
    fakeAvailableResources(
        ['agent' => 1, 'cpus' => 4, 'maxmem' => 8192 * USAGE_MIB, 'status' => 'stopped'],
        ['scsi0' => 'local-lvm:vm-101-disk-0,size=32G', 'scsi1' => 'local-lvm:vm-101-disk-1,size=10G'],
        [
            ['size' => 32 * USAGE_GIB, 'used' => 40 * USAGE_GIB, 'volid' => 'local-lvm:vm-101-disk-0'],
            ['size' => 10 * USAGE_GIB, 'volid' => 'local-lvm:vm-101-disk-1'],
        ],
    );

    expect(usageVirtualMachine()->availableResources())->toBe([
        'cpu' => ['vcpus' => 4, 'used' => 0.0, 'available' => 100.0],
        'memory' => ['total' => 8192, 'used' => 0, 'available' => 8192],
        'disks' => [
            'scsi0' => ['storage' => 'local-lvm', 'total' => 32, 'used' => 32, 'available' => 0],
            'scsi1' => ['storage' => 'local-lvm', 'total' => 10, 'used' => null, 'available' => null],
        ],
        'filesystems' => null,
    ]);

    Http::assertSentCount(3);
});

it('falls back to host-side memory usage and skips the agent when it is not enabled', function () {
    fakeAvailableResources(['cpu' => 1.5, 'cpus' => 2, 'maxmem' => 2048 * USAGE_MIB, 'mem' => 512 * USAGE_MIB, 'status' => 'running']);

    expect(usageVirtualMachine()->availableResources())->toBe([
        'cpu' => ['vcpus' => 2, 'used' => 100.0, 'available' => 0.0],
        'memory' => ['total' => 2048, 'used' => 512, 'available' => 1536],
        'disks' => [],
        'filesystems' => null,
    ]);

    Http::assertSentCount(2);
});

it('returns null filesystems when the guest agent is not running', function () {
    fakeAvailableResources(
        ['agent' => 1, 'cpus' => 1, 'maxmem' => 1024 * USAGE_MIB, 'mem' => 0, 'status' => 'running'],
        agent: [['data' => null], 500],
    );

    expect(usageVirtualMachine()->availableResources()['filesystems'])->toBeNull();
});

it('still throws guest agent permission errors', function () {
    fakeAvailableResources(['agent' => 1, 'status' => 'running'], agent: [['errors' => ['permission' => 'denied']], 403]);

    expect(fn () => usageVirtualMachine()->availableResources())->toThrow(RequestException::class, '403');
});

it('maps RRD chart data for CPU, memory, network, and disk I/O', function () {
    Http::fake(['*/nodes/my/qemu/101/rrddata*' => Http::response(['data' => [
        ['cpu' => 0.123456, 'diskread' => 10.555, 'diskwrite' => 20, 'maxcpu' => 2, 'maxmem' => 4096 * USAGE_MIB, 'mem' => 1024 * USAGE_MIB, 'netin' => 300.126, 'netout' => 400, 'time' => 1_700_000_000],
        ['time' => 1_700_000_060],
        ['cpu' => 0.5],
    ]])]);

    $vm = usageVirtualMachine();

    expect($vm->cpuHistory())->toBe([
        ['time' => 1_700_000_000, 'vcpus' => 2, 'percent' => 12.35],
        ['time' => 1_700_000_060, 'vcpus' => null, 'percent' => null],
    ])->and($vm->memoryHistory('day', 'max'))->toBe([
        ['time' => 1_700_000_000, 'total' => 4096, 'used' => 1024],
        ['time' => 1_700_000_060, 'total' => null, 'used' => null],
    ])->and($vm->networkHistory('week'))->toBe([
        ['time' => 1_700_000_000, 'in' => 300.13, 'out' => 400.0],
        ['time' => 1_700_000_060, 'in' => null, 'out' => null],
    ])->and($vm->diskHistory('year'))->toBe([
        ['time' => 1_700_000_000, 'read' => 10.56, 'write' => 20.0],
        ['time' => 1_700_000_060, 'read' => null, 'write' => null],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu/101/rrddata?timeframe=hour&cf=AVERAGE');
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu/101/rrddata?timeframe=day&cf=MAX');
    Http::assertSentCount(4);
});

it('rejects invalid chart timeframes and consolidation functions before requesting', function (string $timeframe, string $consolidation, string $message) {
    expect(fn () => usageVirtualMachine()->cpuHistory($timeframe, $consolidation))->toThrow(InvalidArgumentException::class, $message);

    Http::assertNothingSent();
})->with([
    ['minute', 'average', 'timeframe'],
    ['hour', 'min', 'consolidation'],
]);
