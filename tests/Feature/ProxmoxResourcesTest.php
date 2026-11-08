<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Proxmox\Facades\ProxmoxCluster;
use Proxmox\Models\Cluster;
use Proxmox\Resources\Nodes\Node;
use Proxmox\Resources\VirtualMachines\VirtualMachine;

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

it('fetches nodes immediately as an array without exposing the response envelope', function () {
    $data = [['node' => 'my', 'status' => 'online']];

    Http::fake(['*/nodes' => Http::response(['data' => $data])]);

    $nodes = ProxmoxCluster::nodes();

    expect($nodes)->toBeArray()->toBe($data);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pam!package=token-secret'));
    Http::assertSentCount(1);
});

it('fetches QEMU virtual machines immediately as an array scoped to a node', function () {
    $data = [
        ['name' => 'web', 'status' => 'running', 'vmid' => 101],
        ['name' => 'worker', 'status' => 'stopped', 'vmid' => 102],
    ];

    Http::fake(['*/nodes/my/qemu' => Http::response(['data' => $data])]);

    $vms = ProxmoxCluster::node('my')->virtualMachines();

    expect($vms)->toBeArray()->toBe($data);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu');
    Http::assertSentCount(1);
});

it('selects a VM without fetching until explicitly refreshed', function () {
    $data = ['name' => 'web', 'status' => 'running', 'vmid' => 101];

    Http::fake(['*/nodes/my/qemu/101/status/current' => Http::response(['data' => $data])]);

    $vm = ProxmoxCluster::node('my')->virtualMachine(101);

    expect($vm)->toBeInstanceOf(VirtualMachine::class)
        ->and($vm->node)->toBe('my')
        ->and($vm->vmid)->toBe(101)
        ->and($vm->toArray())->toBe([]);

    Http::assertNothingSent();

    $vm->refresh();

    expect($vm->toArray())->toBe($data)
        ->and(json_decode(json_encode($vm, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR))->toBe($data)
        ->and(response()->json($vm)->getData(true))->toBe($data);

    Http::assertSentCount(1);
});

it('updates the stored VM data only when explicitly refreshed', function () {
    Http::fake(['*/nodes/my/qemu/101/status/current' => Http::sequence()
        ->push(['data' => ['status' => 'running']])
        ->push(['data' => ['status' => 'stopped']])
        ->push(['data' => ['status' => 'stopped', 'uptime' => 0]]),
    ]);

    $vm = ProxmoxCluster::node('my')->virtualMachine(101)->refresh();

    expect($vm->toArray())->toBe(['status' => 'running'])
        ->and($vm->status())->toBe(['status' => 'stopped'])
        ->and($vm->toArray())->toBe(['status' => 'running'])
        ->and($vm->refresh())->toBe($vm)
        ->and($vm->toArray())->toBe(['status' => 'stopped', 'uptime' => 0]);

    Http::assertSentCount(3);
});

it('preserves fetched VM data when a refresh fails', function (string $failure, string $exception) {
    $data = ['status' => 'running', 'vmid' => 101];
    $sequence = Http::sequence()->push(['data' => $data]);

    match ($failure) {
        'http' => $sequence->push([], 403),
        'malformed' => $sequence->push(['data' => null]),
        'connection' => $sequence->pushFailedConnection(),
    };

    Http::fake(['*' => $sequence]);

    $vm = ProxmoxCluster::node('my')->virtualMachine(101)->refresh();

    expect(fn () => $vm->refresh())->toThrow($exception)
        ->and($vm->toArray())->toBe($data);
})->with([
    ['http', RequestException::class],
    ['malformed', UnexpectedValueException::class],
    ['connection', ConnectionException::class],
]);

it('rejects the removed plural node selector before sending a request', function () {
    expect(fn () => ProxmoxCluster::nodes('my'))
        ->toThrow(InvalidArgumentException::class, 'node(');

    Http::assertNothingSent();
});

it('keeps independently created node scopes separate and reusable', function () {
    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => [['vmid' => 101]]]),
        '*/nodes/other/qemu' => Http::response(['data' => [['vmid' => 201]]]),
    ]);

    $first = ProxmoxCluster::node('my');
    $second = ProxmoxCluster::node('other');

    Http::assertNothingSent();

    expect($first->virtualMachines())->toBe([['vmid' => 101]])
        ->and($second->virtualMachines())->toBe([['vmid' => 201]])
        ->and($first->virtualMachines())->toBe([['vmid' => 101]]);

    Http::assertSentCount(3);
});

it('returns an empty array when a listing has no results', function (bool $vms) {
    Http::fake(['*' => Http::response(['data' => []])]);

    $results = $vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes();

    expect($results)->toBe([]);
    Http::assertSentCount(1);
})->with([false, true]);

it('automatically throws for unsuccessful resource listings', function (int $status, bool $vms) {
    Http::fake(['*' => Http::response(['errors' => ['permission' => 'denied']], $status)]);

    expect(fn () => $vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes())
        ->toThrow(RequestException::class, (string) $status);
})->with([401, 403, 404, 500])->with([false, true]);

it('rejects removed direct resource shortcuts', function (string $method, array $parameters) {
    expect(fn () => ProxmoxCluster::{$method}(...$parameters))->toThrow(BadMethodCallException::class, $method);

    Http::assertNothingSent();
})->with([
    ['getNodes', []],
    ['getNode', ['my']],
    ['getVirtualMachines', ['my']],
    ['getVirtualMachine', ['my', 101]],
    ['getImages', ['my', 'local']],
    ['createVirtualMachine', ['my', ['vmid' => 101]]],
]);

it('propagates connection failures from resource listings', function (bool $vms) {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => $vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes())
        ->toThrow(ConnectionException::class);
})->with([false, true]);

it('rejects malformed listing responses instead of returning empty results', function (array|string $body, bool $vms) {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => $vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes())
        ->toThrow(UnexpectedValueException::class, 'data');
})->with([
    'missing data' => [[]],
    'null data' => [['data' => null]],
    'scalar data' => [['data' => 'invalid']],
    'object instead of list' => [['data' => ['vmid' => 101]]],
    'scalar list entry' => [['data' => [101]]],
    'invalid JSON' => ['{"data":'],
])->with([false, true]);

it('validates node names before sending VM listing requests', function (string $node) {
    expect(fn () => ProxmoxCluster::node($node)->virtualMachines())
        ->toThrow(InvalidArgumentException::class, 'node');

    Http::assertNothingSent();
})->with(['', ' ', '.', '..', '../other', 'my/node', 'my\\node']);

it('encodes a node name as one path segment', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    ProxmoxCluster::node(' my?node#name ')->virtualMachines();

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://proxmox.example.test:8006/api2/json/nodes/my%3Fnode%23name/qemu');
});

it('keeps the generic HTTP methods available for raw responses', function () {
    Http::fake(['*/nodes' => Http::response(['errors' => ['permission' => 'denied']], 403)]);

    $response = ProxmoxCluster::get('nodes');

    expect($response->status())->toBe(403)
        ->and($response->json('errors'))->toBe(['permission' => 'denied']);
});

it('returns independent array snapshots for successive listing requests', function (bool $vms) {
    $first = $vms ? [['vmid' => 101]] : [['node' => 'my']];
    $second = $vms ? [['vmid' => 102]] : [['node' => 'other']];

    Http::fake(['*' => Http::sequence()->push(['data' => $first])->push(['data' => $second])]);

    $original = $vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes();
    $latest = $vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes();

    expect($original)->toBe($first)
        ->and($latest)->toBe($second);

    Http::assertSentCount(2);
})->with([false, true]);

it('lets consumers filter fetched arrays without further API requests', function (bool $vms) {
    $data = [
        ['maxmem' => 1024, 'name' => 'first', 'status' => 'stopped'],
        ['maxmem' => 2048, 'name' => 'second', 'status' => 'running'],
        ['maxmem' => 4096, 'name' => 'third', 'status' => 'running'],
    ];

    Http::fake(['*' => Http::response(['data' => $data])]);

    $items = collect($vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes());
    $running = $items->where('status', 'running');

    expect($running->where('maxmem', '>=', 4096)->first())->toBe($data[2])
        ->and($running->values()->all())->toBe([$data[1], $data[2]])
        ->and($items->where('status', 'stopped')->values()->all())->toBe([$data[0]])
        ->and($items->all())->toBe($data);

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/'
            .($vms ? 'nodes/my/qemu' : 'nodes')
        && $request->data() === []);
})->with([false, true]);

it('preserves attribute types for consumer collection comparisons', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['name' => null, 'vmid' => 101],
        ['name' => 'worker', 'vmid' => 102],
    ]])]);

    $items = collect(ProxmoxCluster::node('my')->virtualMachines());

    expect($items->where('vmid', '101')->first())->toBe(['name' => null, 'vmid' => 101])
        ->and($items->where('vmid', '===', '101')->first())->toBeNull()
        ->and($items->where('vmid', '!=', 101)->first())->toBe(['name' => 'worker', 'vmid' => 102])
        ->and($items->where('name', null)->first())->toBe(['name' => null, 'vmid' => 101]);

    Http::assertSentCount(1);
});

it('keeps fetched list data usable when a later listing is empty', function (bool $vms) {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [['name' => 'first'], ['name' => 'second']]])
        ->push(['data' => []]),
    ]);

    $items = collect($vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes());
    $empty = collect($vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes());

    expect($items->first())->toBe(['name' => 'first'])
        ->and($items->where('name', 'missing')->first())->toBeNull()
        ->and($empty->first())->toBeNull()
        ->and($items->count())->toBe(2);

    Http::assertSentCount(2);
})->with([false, true]);

it('requires an identifier when selecting a VM', function () {
    expect(fn () => ProxmoxCluster::node('my')->virtualMachine())->toThrow(ArgumentCountError::class);

    Http::assertNothingSent();
});

it('raises listing errors before consumers can filter the returned data', function (string $failure, bool $vms) {
    Http::fake(['*' => match ($failure) {
        'http' => Http::response(['errors' => ['permission' => 'denied']], 403),
        'malformed' => Http::response(['data' => null]),
        'connection' => Http::failedConnection(),
    }]);

    $exception = match ($failure) {
        'http' => RequestException::class,
        'malformed' => UnexpectedValueException::class,
        'connection' => ConnectionException::class,
    };

    expect(fn () => collect($vms ? ProxmoxCluster::node('my')->virtualMachines() : ProxmoxCluster::nodes())
        ->where('name', 'missing')->first())->toThrow($exception);
})->with(['http', 'malformed', 'connection'])->with([false, true]);

it('retrieves individual resource data from its status endpoint', function (bool $vms) {
    $data = $vms
        ? ['status' => 'running', 'uptime' => 100, 'vmid' => 101]
        : ['memory' => ['total' => 8192], 'uptime' => 100];
    $endpoint = $vms ? 'nodes/my/qemu/101/status/current' : 'nodes/my/status';

    Http::fake(['*' => Http::response(['data' => $data])]);

    $attributes = $vms ? ProxmoxCluster::node('my')->virtualMachine(101)->status() : ProxmoxCluster::node('my')->status();

    expect($attributes)->toBe($data);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/'.$endpoint
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pam!package=token-secret'));
    Http::assertSentCount(1);
})->with([false, true]);

it('keeps query methods out of selected resources', function (bool $vms) {
    Http::fake(['*' => Http::response(['data' => ['status' => 'running']])]);

    $node = ProxmoxCluster::node('my');
    $resource = $vms ? $node->virtualMachine(101) : $node;

    expect($resource)->toBeInstanceOf($vms ? VirtualMachine::class : Node::class);

    foreach (['all', 'where', 'get', 'first'] as $method) {
        expect(method_exists($resource, $method))->toBeFalse();
    }
})->with([false, true]);

it('uses distinct methods for a VM listing and an identified VM', function () {
    $vmList = [['name' => 'web', 'vmid' => 101]];
    $vmStatus = ['status' => 'running', 'vmid' => 101];

    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => $vmList]),
        '*/nodes/my/qemu/101/status/current' => Http::response(['data' => $vmStatus]),
    ]);

    $node = ProxmoxCluster::node('my');
    $list = $node->virtualMachines();
    $vm = $node->virtualMachine(101)->refresh();

    expect($list)->toBeArray()->toBe($vmList)
        ->and($vm)->toBeInstanceOf(VirtualMachine::class)
        ->and($vm->toArray())->toBe($vmStatus);

    Http::assertSentCount(2);
});

it('creates a VM with native Proxmox QEMU resource and cloud-init options', function () {
    $configuration = [
        'vmid' => 101,
        'name' => 'web-101',
        'cores' => 2,
        'memory' => 4096,
        'scsi0' => 'local-lvm:32',
        'net0' => 'virtio,bridge=vmbr0',
        'ide2' => 'local-lvm:cloudinit',
        'ipconfig0' => 'ip=192.0.2.10/24,gw=192.0.2.1',
    ];
    $task = 'UPID:my:00000001:00000001:00000001:qmcreate:101:root@pam:';

    Http::fake(['*/nodes/my/qemu' => Http::response(['data' => $task])]);

    expect(ProxmoxCluster::node('my')->createVirtualMachine($configuration)->upid)->toBe($task);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu'
        && $request->data() === $configuration
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pam!package=token-secret'));
    Http::assertSentCount(1);
});

it('throws API errors when VM creation fails', function (int $status) {
    Http::fake(['*' => Http::response(['errors' => ['vmid' => 'already exists']], $status)]);

    expect(fn () => ProxmoxCluster::node('my')->createVirtualMachine(['vmid' => 101]))
        ->toThrow(RequestException::class, (string) $status);
})->with([400, 403, 500]);

it('rejects malformed task IDs from VM creation responses', function (array|string $body) {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => ProxmoxCluster::node('my')->createVirtualMachine(['vmid' => 101]))
        ->toThrow(UnexpectedValueException::class, 'task ID');
})->with([
    'missing data' => [[]],
    'null data' => [['data' => null]],
    'array data' => [['data' => ['vmid' => 101]]],
    'empty task ID' => [['data' => ' ']],
    'invalid JSON' => ['{"data":'],
]);

it('propagates connection failures when creating a VM', function () {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => ProxmoxCluster::node('my')->createVirtualMachine(['vmid' => 101]))
        ->toThrow(ConnectionException::class);
});

it('validates node names before creating a VM', function (string $node) {
    expect(fn () => ProxmoxCluster::node($node)->createVirtualMachine(['vmid' => 101]))
        ->toThrow(InvalidArgumentException::class, 'node');

    Http::assertNothingSent();
})->with(['', ' ', '.', '..', '../other', 'my/node', 'my\\node']);

it('keeps fetched VM snapshots independent and accepts integer or string identifiers', function () {
    Http::fake([
        '*/nodes/my/qemu/101/status/current' => Http::response(['data' => ['vmid' => 101]]),
        '*/nodes/other/qemu/201/status/current' => Http::response(['data' => ['vmid' => 201]]),
    ]);

    $first = ProxmoxCluster::node('my')->virtualMachine('101')->refresh();
    $second = ProxmoxCluster::node('other')->virtualMachine(201)->refresh();

    expect($first->toArray())->toBe(['vmid' => 101])
        ->and($second->toArray())->toBe(['vmid' => 201])
        ->and($first->toArray())->toBe(['vmid' => 101]);

    Http::assertSentCount(2);
});

it('rejects invalid VM identifiers before sending a request', function (int|string $vmid) {
    expect(fn () => ProxmoxCluster::node('my')->virtualMachine($vmid))
        ->toThrow(InvalidArgumentException::class, 'VM ID');

    Http::assertNothingSent();
})->with([0, -1, '', ' ', '0', '-1', '0101', '1.5', '../101', '101/config', '101?x=1', '101#fragment']);

it('validates node names as soon as an individual resource is selected', function (bool $vms) {
    expect(fn () => $vms ? ProxmoxCluster::node('../other')->virtualMachine(101) : ProxmoxCluster::node('../other'))
        ->toThrow(InvalidArgumentException::class, 'node');

    Http::assertNothingSent();
})->with([false, true]);

it('encodes node names in individual resource paths', function (bool $vms) {
    Http::fake(['*' => Http::response(['data' => ['uptime' => 100]])]);

    $node = ProxmoxCluster::node(' my?node#name ');
    $vms ? $node->virtualMachine(101)->status() : $node->status();

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://proxmox.example.test:8006/api2/json/nodes/my%3Fnode%23name/'
            .($vms ? 'qemu/101/status/current' : 'status'));
})->with([false, true]);

it('automatically throws for unsuccessful individual resource requests', function (int $status, bool $vms) {
    Http::fake(['*' => Http::response(['errors' => ['permission' => 'denied']], $status)]);

    expect(fn () => $vms ? ProxmoxCluster::node('my')->virtualMachine(101)->status() : ProxmoxCluster::node('my')->status())
        ->toThrow(RequestException::class, (string) $status);
})->with([401, 403, 404, 500])->with([false, true]);

it('propagates connection failures from individual resources', function (bool $vms) {
    Http::fake(['*' => Http::failedConnection()]);

    expect(fn () => $vms ? ProxmoxCluster::node('my')->virtualMachine(101)->status() : ProxmoxCluster::node('my')->status())
        ->toThrow(ConnectionException::class);
})->with([false, true]);

it('rejects malformed individual resource data', function (array|string $body, bool $vms) {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => $vms ? ProxmoxCluster::node('my')->virtualMachine(101)->status() : ProxmoxCluster::node('my')->status())
        ->toThrow(UnexpectedValueException::class, 'data');
})->with([
    'missing data' => [[]],
    'null data' => [['data' => null]],
    'scalar data' => [['data' => 'invalid']],
    'list instead of attributes' => [['data' => [['vmid' => 101]]]],
    'empty list' => [['data' => []]],
    'invalid JSON' => ['{"data":'],
])->with([false, true]);
