<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proxmox\Facades\ProxmoxCluster;
use Proxmox\Models\Cluster;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    $this->cluster = Cluster::query()->create([
        'name' => 'default',
        'url' => 'https://proxmox.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pam!package',
        'api_token_secret' => 'token-secret',
    ]);

    Http::preventStrayRequests();
});

it('translates readable fields through facade and client creation', function (bool $direct) {
    $configuration = [
        'vmid' => 101,
        'name' => 'web-101',
        'cores' => 2,
        'memory' => 4096,
        'disk' => 32,
        'storage' => 'local-lvm',
        'bridge' => 'vmbr0',
        'ip' => '192.0.2.10',
        'prefix' => 24,
        'gateway' => '192.0.2.1',
        'ciuser' => 'ubuntu',
    ];
    $task = 'UPID:my:00000001:00000001:00000001:qmcreate:101:root@pam:';

    Http::fake(['*' => Http::response(['data' => $task])]);

    $node = $direct
        ? ProxmoxCluster::connection()->node('my')
        : ProxmoxCluster::node('my');
    $result = $node->createVirtualMachine($configuration);

    expect($result->upid)->toBe($task)
        ->and($configuration['disk'])->toBe(32)
        ->and($configuration)->not->toHaveKey('scsi0');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
        && $request->data() == [
            'vmid' => 101,
            'name' => 'web-101',
            'cores' => 2,
            'memory' => 4096,
            'storage' => 'local-lvm',
            'scsi0' => 'local-lvm:32',
            'scsihw' => 'virtio-scsi-pci',
            'net0' => 'virtio,bridge=vmbr0',
            'ide2' => 'local-lvm:cloudinit',
            'ipconfig0' => 'ip=192.0.2.10/24,gw=192.0.2.1',
            'ciuser' => 'ubuntu',
        ]);
    Http::assertSentCount(1);
})->with([false, true]);

it('supports DHCP without a prefix or gateway', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:creation'])]);

    ProxmoxCluster::node('my')->createVirtualMachine([
        'vmid' => 101,
        'storage' => 'local-lvm',
        'bridge' => 'vmbr0',
        'ip' => 'dhcp',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->data() == [
        'vmid' => 101,
        'storage' => 'local-lvm',
        'net0' => 'virtio,bridge=vmbr0',
        'ide2' => 'local-lvm:cloudinit',
        'ipconfig0' => 'ip=dhcp',
    ]);
});

it('supports static addresses without a gateway and the full IPv4 prefix range', function (int $prefix) {
    Http::fake(['*' => Http::response(['data' => 'UPID:creation'])]);

    ProxmoxCluster::node('my')->createVirtualMachine([
        'vmid' => 101,
        'storage' => 'local-lvm',
        'bridge' => 'vmbr0',
        'ip' => '192.0.2.10',
        'prefix' => $prefix,
    ]);

    Http::assertSent(fn (Request $request): bool => $request['ipconfig0'] === 'ip=192.0.2.10/'.$prefix);
})->with([0, 32]);

it('allows readable disks and network cards without Cloud-Init', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:creation'])]);

    ProxmoxCluster::node('my')->createVirtualMachine([
        'vmid' => 101,
        'disk' => 16,
        'storage' => 'fast_ssd-1',
        'bridge' => 'vmbr1',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->data() == [
        'vmid' => 101,
        'storage' => 'fast_ssd-1',
        'scsi0' => 'fast_ssd-1:16',
        'scsihw' => 'virtio-scsi-pci',
        'net0' => 'virtio,bridge=vmbr1',
    ]);
});

it('can use an explicitly configured network card with a readable IP', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:creation'])]);

    ProxmoxCluster::node('my')->createVirtualMachine([
        'vmid' => 101,
        'storage' => 'local-lvm',
        'net0' => 'virtio,bridge=vmbr0,tag=10',
        'ip' => '192.0.2.10',
        'prefix' => 24,
    ]);

    Http::assertSent(fn (Request $request): bool => $request['net0'] === 'virtio,bridge=vmbr0,tag=10'
        && $request['ide2'] === 'local-lvm:cloudinit'
        && $request['ipconfig0'] === 'ip=192.0.2.10/24');
});

it('preserves an explicitly chosen disk controller with a readable disk', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:creation'])]);

    ProxmoxCluster::node('my')->createVirtualMachine([
        'vmid' => 101,
        'disk' => 32,
        'storage' => 'local-lvm',
        'scsihw' => 'virtio-scsi-single',
    ]);

    Http::assertSent(fn (Request $request): bool => $request['scsi0'] === 'local-lvm:32'
        && $request['scsihw'] === 'virtio-scsi-single');
});

it('keeps native creation options unchanged and does not leak readable settings between calls', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:creation'])]);

    $node = ProxmoxCluster::node('my');
    $node->createVirtualMachine([
        'vmid' => 101,
        'disk' => 32,
        'storage' => 'local-lvm',
        'bridge' => 'vmbr0',
        'ip' => 'dhcp',
    ]);

    $native = [
        'vmid' => 102,
        'description' => 'A VM with additional native parameters',
        'agent' => 1,
        'tags' => 'web;production',
        'storage' => 'other-storage',
        'scsi0' => 'other-storage:64,discard=on',
        'net0' => 'e1000,bridge=vmbr1',
        'ide2' => 'local:iso/installer.iso,media=cdrom',
        'ipconfig0' => 'ip6=auto',
    ];

    $node->createVirtualMachine($native);

    Http::assertSent(fn (Request $request): bool => $request->data() === $native);
    Http::assertSentCount(2);
});

it('rejects invalid readable creation fields before sending a request', function (array $invalid, string $field) {
    $configuration = array_replace([
        'vmid' => 101,
        'disk' => 32,
        'storage' => 'local-lvm',
        'bridge' => 'vmbr0',
        'ip' => '192.0.2.10',
        'prefix' => 24,
        'gateway' => '192.0.2.1',
    ], $invalid);

    expect(fn () => ProxmoxCluster::node('my')->createVirtualMachine($configuration))
        ->toThrow(InvalidArgumentException::class, $field);

    Http::assertNothingSent();
})->with([
    'zero disk' => [['disk' => 0], 'disk'],
    'negative disk' => [['disk' => -1], 'disk'],
    'string disk' => [['disk' => '32'], 'disk'],
    'fractional disk' => [['disk' => 1.5], 'disk'],
    'null disk' => [['disk' => null], 'disk'],
    'array disk' => [['disk' => []], 'disk'],
    'null storage' => [['storage' => null], 'storage'],
    'empty storage' => [['storage' => ''], 'storage'],
    'storage options' => [['storage' => 'local-lvm,discard=on'], 'storage'],
    'storage path' => [['storage' => '../storage'], 'storage'],
    'null bridge' => [['bridge' => null], 'bridge'],
    'empty bridge' => [['bridge' => ''], 'bridge'],
    'bridge options' => [['bridge' => 'vmbr0,tag=10'], 'bridge'],
    'bridge whitespace' => [['bridge' => "vmbr0\n"], 'bridge'],
    'invalid IP' => [['ip' => '999.0.2.10'], 'ip'],
    'IP with embedded prefix' => [['ip' => '192.0.2.10/24'], 'ip'],
    'IPv6 via IPv4 helper' => [['ip' => '2001:db8::1'], 'ip'],
    'IP options' => [['ip' => '192.0.2.10,gw=192.0.2.2'], 'ip'],
    'null IP' => [['ip' => null], 'ip'],
    'negative prefix' => [['prefix' => -1], 'prefix'],
    'prefix too large' => [['prefix' => 33], 'prefix'],
    'string prefix' => [['prefix' => '24'], 'prefix'],
    'null prefix' => [['prefix' => null], 'prefix'],
    'invalid gateway' => [['gateway' => 'not-an-ip'], 'gateway'],
    'null gateway' => [['gateway' => null], 'gateway'],
    'gateway options' => [['gateway' => '192.0.2.1,ip6=auto'], 'gateway'],
    'disk conflict' => [['scsi0' => 'local-lvm:64'], 'scsi0'],
    'network conflict' => [['net0' => 'e1000,bridge=vmbr1'], 'net0'],
    'IP conflict' => [['ipconfig0' => 'ip=dhcp'], 'ipconfig0'],
    'Cloud-Init slot conflict' => [['ide2' => 'local:iso/installer.iso,media=cdrom'], 'ide2'],
]);

it('requires explicit dependencies for readable creation fields', function (array $configuration, string $field) {
    expect(fn () => ProxmoxCluster::node('my')->createVirtualMachine(['vmid' => 101, ...$configuration]))
        ->toThrow(InvalidArgumentException::class, $field);

    Http::assertNothingSent();
})->with([
    'disk storage' => [['disk' => 32], 'storage'],
    'IP storage' => [['ip' => 'dhcp', 'bridge' => 'vmbr0'], 'storage'],
    'IP network card' => [['ip' => 'dhcp', 'storage' => 'local-lvm'], 'bridge'],
    'static prefix' => [['ip' => '192.0.2.10', 'storage' => 'local-lvm', 'bridge' => 'vmbr0'], 'prefix'],
    'orphan prefix' => [['prefix' => 24], 'ip'],
    'orphan gateway' => [['gateway' => '192.0.2.1'], 'ip'],
    'DHCP prefix' => [['ip' => 'dhcp', 'prefix' => 24, 'storage' => 'local-lvm', 'bridge' => 'vmbr0'], 'prefix'],
    'DHCP gateway' => [['ip' => 'dhcp', 'gateway' => '192.0.2.1', 'storage' => 'local-lvm', 'bridge' => 'vmbr0'], 'gateway'],
]);
