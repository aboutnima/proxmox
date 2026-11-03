<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Proxmox\Facades\Proxmox;

beforeEach(function () {
    config()->set([
        'proxmox.connections.default.url' => 'https://proxmox.example.test:8006/api2/json',
        'proxmox.connections.default.api_token_id' => 'laravel@pam!package',
        'proxmox.connections.default.api_token_secret' => 'token-secret',
    ]);

    Http::preventStrayRequests();
});

it('keeps VM status snapshot and JSON keys stable across differently ordered responses', function () {
    $expected = [
        'cpu' => 0.05,
        'empty' => [],
        'ha' => ['managed' => false, 'state' => 'started'],
        'name' => 'web-100',
        'nics' => [
            ['name' => 'eth1', 'rx' => 20],
            ['name' => 'eth0', 'rx' => 10],
        ],
        'optional' => null,
        'status' => 'running',
        'vmid' => 100,
    ];

    Http::fake(['*/status/current' => Http::sequence()
        ->push(['data' => [
            'vmid' => 100,
            'status' => 'running',
            'optional' => null,
            'nics' => [
                ['rx' => 20, 'name' => 'eth1'],
                ['rx' => 10, 'name' => 'eth0'],
            ],
            'name' => 'web-100',
            'ha' => ['state' => 'started', 'managed' => false],
            'empty' => [],
            'cpu' => 0.05,
        ]])
        ->push(['data' => $expected])
        ->push(['data' => ['vmid' => 100, 'cpu' => 0.25, 'status' => 'stopped']]),
    ]);

    $vm = Proxmox::node('my')->virtualMachine(100);

    expect($vm->toArray())->toBe($expected)
        ->and($vm->status())->toBe($expected)
        ->and($vm->jsonSerialize())->toBe($expected)
        ->and(json_decode(json_encode($vm, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($expected);

    Http::assertSentCount(2);

    $vm->refresh();

    expect($vm->toArray())->toBe(['cpu' => 0.25, 'status' => 'stopped', 'vmid' => 100]);
    Http::assertSentCount(3);
});

it('keeps VM listing keys stable without reordering VMs or nested list values', function () {
    $expected = [
        ['name' => 'worker', 'tags' => ['z-last', 'a-first'], 'vmid' => 201],
        ['name' => 'web', 'tags' => [], 'vmid' => 101],
    ];

    Http::fake(['*/qemu' => Http::sequence()
        ->push(['data' => [
            ['vmid' => 201, 'tags' => ['z-last', 'a-first'], 'name' => 'worker'],
            ['vmid' => 101, 'tags' => [], 'name' => 'web'],
        ]])
        ->push(['data' => $expected]),
    ]);

    $vms = Proxmox::node('my')->virtualMachines();

    expect($vms->all())->toBe($expected)
        ->and($vms->all())->toBe($expected);

    Http::assertSentCount(2);
});

it('keeps VM configuration keys stable across differently ordered responses', function () {
    $expected = ['cores' => 2, 'memory' => 2048, 'name' => 'web-100'];

    Http::fake([
        '*/status/current' => Http::response(['data' => ['vmid' => 100, 'status' => 'running']]),
        '*/config' => Http::sequence()
            ->push(['data' => ['name' => 'web-100', 'memory' => 2048, 'cores' => 2]])
            ->push(['data' => $expected]),
    ]);

    $vm = Proxmox::node('my')->virtualMachine(100);

    expect($vm->config())->toBe($expected)
        ->and($vm->config())->toBe($expected);

    Http::assertSentCount(3);
});
