<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
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

it('calculates how far a VM can grow on its node and storage', function (string $status, array $memory) {
    fakeVirtualMachineConfig([
        'cores' => 2,
        'memory' => 4096,
        'scsi0' => 'local-lvm:vm-101-disk-0,size=32G',
        'scsi1' => 'backup:vm-101-disk-1,size=8G',
        'sockets' => 2,
    ]);

    Http::fake([
        '*/nodes/my/qemu/101/status/current' => Http::response(['data' => ['status' => $status]]),
        '*/nodes/my/status' => Http::response(['data' => [
            'cpuinfo' => ['cpus' => 16],
            'memory' => ['free' => 2048 * USAGE_MIB, 'total' => 65536 * USAGE_MIB, 'used' => 63488 * USAGE_MIB],
        ]]),
        '*/nodes/my/storage*' => Http::response(['data' => [
            ['avail' => 400 * USAGE_GIB, 'storage' => 'local-lvm', 'total' => 500 * USAGE_GIB, 'used' => 100 * USAGE_GIB],
        ]]),
    ]);

    expect(usageVirtualMachine()->availableResources())->toBe([
        'cpu' => ['max' => 16, 'additional' => 12],
        'memory' => $memory,
        'disks' => [
            'scsi0' => ['storage' => 'local-lvm', 'max' => 432, 'additional' => 400],
            'scsi1' => ['storage' => 'backup', 'max' => null, 'additional' => null],
        ],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/storage?enabled=1&content=images');
    Http::assertSentCount(4);
})->with([
    'running VM keeps its allocation' => ['running', ['max' => 6144, 'additional' => 2048]],
    'stopped VM needs free node memory' => ['stopped', ['max' => 2048, 'additional' => 0]],
]);
