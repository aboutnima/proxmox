<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Proxmox\Facades\Proxmox;
use Proxmox\Resources\Node;
use Proxmox\Resources\VirtualMachine;
use Proxmox\Resources\VirtualMachines;

beforeEach(function () {
    config()->set([
        'proxmox.url' => 'https://proxmox.example.test:8006/api2/json',
        'proxmox.api_token_id' => 'laravel@pam!package',
        'proxmox.api_token_secret' => 'token-secret',
    ]);

    Http::preventStrayRequests();
});

it('lists nodes as a collection without exposing the response envelope', function () {
    $data = [['node' => 'my', 'status' => 'online']];

    Http::fake(['*/nodes' => Http::response(['data' => $data])]);

    $nodes = Proxmox::nodes()->get();

    expect($nodes)->toBeInstanceOf(Collection::class)
        ->and($nodes->all())->toBe($data)
        ->and($nodes->pluck('node')->all())->toBe(['my']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pam!package=token-secret'));
    Http::assertSentCount(1);
});

it('lists QEMU virtual machines as a collection scoped to a node', function () {
    $data = [
        ['vmid' => 101, 'name' => 'web', 'status' => 'running'],
        ['vmid' => 102, 'name' => 'worker', 'status' => 'stopped'],
    ];

    Http::fake(['*/nodes/my/qemu' => Http::response(['data' => $data])]);

    $vms = Proxmox::node('my')->virtualMachine()->get();

    expect($vms)->toBeInstanceOf(Collection::class)
        ->and($vms->all())->toBe($data)
        ->and($vms->where('status', 'running')->pluck('vmid')->all())->toBe([101]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu');
    Http::assertSentCount(1);
});

it('keeps independently created node scopes separate and reusable', function () {
    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => [['vmid' => 101]]]),
        '*/nodes/other/qemu' => Http::response(['data' => [['vmid' => 201]]]),
    ]);

    $first = Proxmox::node('my')->virtualMachine();
    $second = Proxmox::node('other')->virtualMachine();

    Http::assertNothingSent();

    expect($first->get()->pluck('vmid')->all())->toBe([101])
        ->and($second->get()->pluck('vmid')->all())->toBe([201])
        ->and($first->get()->pluck('vmid')->all())->toBe([101]);

    Http::assertSentCount(3);
});

it('returns an empty collection when a listing has no results', function (bool $vms) {
    Http::fake(['*' => Http::response(['data' => []])]);

    $results = $vms ? Proxmox::node('my')->virtualMachine()->get() : Proxmox::nodes()->get();

    expect($results)->toBeInstanceOf(Collection::class)
        ->and($results->isEmpty())->toBeTrue();
})->with([false, true]);

it('automatically throws for unsuccessful resource listings', function (int $status, bool $vms) {
    Http::fake(['*' => Http::response(['errors' => ['permission' => 'denied']], $status)]);

    $resource = $vms ? Proxmox::node('my')->virtualMachine() : Proxmox::nodes();

    expect(fn () => $resource->get())->toThrow(RequestException::class, (string) $status);
})->with([401, 403, 404, 500])->with([false, true]);

it('also handles HTTP errors in the direct listing shortcuts', function (bool $vms) {
    Http::fake(['*' => Http::response(['errors' => ['permission' => 'denied']], 403)]);

    expect(fn () => $vms ? Proxmox::getVirtualMachines('my') : Proxmox::getNodes())
        ->toThrow(RequestException::class, '403');
})->with([false, true]);

it('propagates connection failures from resource listings', function (bool $vms) {
    Http::fake(['*' => Http::failedConnection()]);

    $resource = $vms ? Proxmox::node('my')->virtualMachine() : Proxmox::nodes();

    expect(fn () => $resource->get())->toThrow(ConnectionException::class);
})->with([false, true]);

it('rejects malformed listing responses instead of returning empty results', function (array|string $body, bool $vms) {
    Http::fake(['*' => Http::response($body)]);

    $resource = $vms ? Proxmox::node('my')->virtualMachine() : Proxmox::nodes();

    expect(fn () => $resource->get())->toThrow(UnexpectedValueException::class, 'data');
})->with([
    'missing data' => [[]],
    'null data' => [['data' => null]],
    'scalar data' => [['data' => 'invalid']],
    'object instead of list' => [['data' => ['vmid' => 101]]],
    'scalar list entry' => [['data' => [101]]],
    'invalid JSON' => ['{"data":'],
])->with([false, true]);

it('validates node names before sending fluent VM listing requests', function (string $node) {
    expect(fn () => Proxmox::node($node)->virtualMachine()->get())
        ->toThrow(InvalidArgumentException::class, 'node');

    Http::assertNothingSent();
})->with(['', ' ', '.', '..', '../other', 'my/node', 'my\\node']);

it('encodes a node name as one path segment', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    Proxmox::node(' my?node#name ')->virtualMachine()->get();

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://proxmox.example.test:8006/api2/json/nodes/my%3Fnode%23name/qemu');
});

it('keeps the generic HTTP methods available for raw responses', function () {
    Http::fake(['*/nodes' => Http::response(['errors' => ['permission' => 'denied']], 403)]);

    $response = Proxmox::get('nodes');

    expect($response->status())->toBe(403)
        ->and($response->json('errors'))->toBe(['permission' => 'denied']);
});

it('lists resources through all while preserving the get alias', function (bool $vms) {
    $data = $vms ? [['vmid' => 101]] : [['node' => 'my']];

    Http::fake(['*' => Http::response(['data' => $data])]);

    $query = $vms ? Proxmox::node('my')->virtualMachine() : Proxmox::nodes();

    expect($query->all())->toBeInstanceOf(Collection::class)
        ->and($query->all()->all())->toBe($data)
        ->and($query->get()->all())->toBe($data);
})->with([false, true]);

it('filters resource lists lazily with independent reusable queries', function (bool $vms) {
    $data = [
        ['name' => 'first', 'status' => 'stopped', 'maxmem' => 1024],
        ['name' => 'second', 'status' => 'running', 'maxmem' => 2048],
        ['name' => 'third', 'status' => 'running', 'maxmem' => 4096],
    ];

    Http::fake(['*' => Http::response(['data' => $data])]);

    $query = $vms ? Proxmox::node('my')->virtualMachine() : Proxmox::nodes();
    $running = $query->where('status', 'running');
    $larger = $running->where('maxmem', '>=', 4096);
    $stopped = $query->where('status', 'stopped');

    Http::assertNothingSent();

    expect($larger->first())->toBe($data[2])
        ->and($running->all()->all())->toBe([$data[1], $data[2]])
        ->and($stopped->get()->all())->toBe([$data[0]])
        ->and($query->all()->all())->toBe($data)
        ->and($larger->all()->all())->toBe([$data[2]]);

    Http::assertSentCount(5);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/'
            .($vms ? 'nodes/my/qemu' : 'nodes')
        && $request->data() === []);
})->with([false, true]);

it('uses Laravel comparison semantics for list filters', function () {
    Http::fake(['*' => Http::response(['data' => [
        ['vmid' => 101, 'name' => null],
        ['vmid' => 102, 'name' => 'worker'],
    ]])]);

    $query = Proxmox::node('my')->virtualMachine();

    expect($query->where('vmid', '101')->first())->toBe(['vmid' => 101, 'name' => null])
        ->and($query->where('vmid', '===', '101')->first())->toBeNull()
        ->and($query->where('vmid', '!=', 101)->first())->toBe(['vmid' => 102, 'name' => 'worker'])
        ->and($query->where('name', null)->first())->toBe(['vmid' => 101, 'name' => null]);
});

it('returns the first list item or null when nothing matches', function (bool $vms) {
    Http::fake(['*' => Http::sequence()
        ->push(['data' => [['name' => 'first'], ['name' => 'second']]])
        ->push(['data' => [['name' => 'first']]])
        ->push(['data' => []]),
    ]);

    $query = $vms ? Proxmox::node('my')->virtualMachine() : Proxmox::nodes();

    expect($query->first())->toBe(['name' => 'first'])
        ->and($query->where('name', 'missing')->first())->toBeNull()
        ->and($query->first())->toBeNull();
})->with([false, true]);

it('rejects invalid filters before sending a request', function (bool $vms) {
    $query = $vms ? Proxmox::node('my')->virtualMachine() : Proxmox::nodes();

    expect(fn () => $query->where('', 'value'))->toThrow(InvalidArgumentException::class, 'key')
        ->and(fn () => $query->where(' ', 'value'))->toThrow(InvalidArgumentException::class, 'key')
        ->and(fn () => $query->where('status', 'like', 'run%'))->toThrow(InvalidArgumentException::class, 'operator')
        ->and(fn () => $query->where('status', null, 'running'))->toThrow(InvalidArgumentException::class, 'operator');

    Http::assertNothingSent();
})->with([false, true]);

it('does not hide errors behind filtered list terminals', function (string $failure, string $terminal, bool $vms) {
    $query = ($vms ? Proxmox::node('my')->virtualMachine() : Proxmox::nodes())->where('name', 'missing');

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

    expect(fn () => $query->{$terminal}())->toThrow($exception);
})->with(['http', 'malformed', 'connection'])->with(['all', 'first'])->with([false, true]);

it('retrieves an individual resource from its status endpoint', function (bool $vms) {
    $data = $vms
        ? ['vmid' => 101, 'status' => 'running', 'uptime' => 100]
        : ['uptime' => 100, 'memory' => ['total' => 8192]];
    $endpoint = $vms ? 'nodes/my/qemu/101/status/current' : 'nodes/my/status';

    Http::fake(['*' => Http::response(['data' => $data])]);

    $resource = $vms ? Proxmox::node('my')->vm(101) : Proxmox::node('my');

    Http::assertNothingSent();

    expect($resource->get())->toBe($data)
        ->and($resource->first())->toBe($data);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/'.$endpoint
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pam!package=token-secret'));
    Http::assertSentCount(2);
})->with([false, true]);

it('restricts identified resources to the single resource API', function (bool $plural, bool $vms) {
    Http::fake(['*' => Http::response(['data' => ['status' => 'running']])]);

    $node = $plural ? Proxmox::nodes('my') : Proxmox::node('my');
    $resource = $vms ? ($plural ? $node->virtualMachine('101') : $node->vm(101)) : $node;

    expect($resource)->toBeInstanceOf($vms ? VirtualMachine::class : Node::class)
        ->and(method_exists($resource, 'all'))->toBeFalse()
        ->and(method_exists($resource, 'where'))->toBeFalse()
        ->and($resource->first())->toBe(['status' => 'running']);

    expect(fn () => $resource->all())->toThrow(Error::class, 'undefined method');

    Http::assertSentCount(1);
})->with([false, true])->with([false, true]);

it('uses virtualMachine for both the VM list and ID-selected VM', function () {
    $vmList = [['vmid' => 101, 'name' => 'web']];
    $vmStatus = ['vmid' => 101, 'status' => 'running'];

    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => $vmList]),
        '*/nodes/my/qemu/101/status/current' => Http::response(['data' => $vmStatus]),
    ]);

    $node = Proxmox::node('my');
    $query = $node->virtualMachine();
    $vm = $node->virtualMachine(101);

    expect($query)->toBeInstanceOf(VirtualMachines::class)
        ->and($query->all()->all())->toBe($vmList)
        ->and($vm)->toBeInstanceOf(VirtualMachine::class)
        ->and($vm->first())->toBe($vmStatus)
        ->and(method_exists($vm, 'all'))->toBeFalse()
        ->and(method_exists($node, 'vms'))->toBeFalse();

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

    expect(Proxmox::node('my')->virtualMachine()->create($configuration))->toBe($task);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu'
        && $request->data() === $configuration
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pam!package=token-secret'));
    Http::assertSentCount(1);
});

it('throws API errors when VM creation fails', function (int $status) {
    Http::fake(['*' => Http::response(['errors' => ['vmid' => 'already exists']], $status)]);

    expect(fn () => Proxmox::node('my')->virtualMachine()->create(['vmid' => 101]))
        ->toThrow(RequestException::class, (string) $status);
})->with([400, 403, 500]);

it('rejects malformed task IDs from VM creation responses', function (array|string $body) {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => Proxmox::node('my')->virtualMachine()->create(['vmid' => 101]))
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

    expect(fn () => Proxmox::node('my')->virtualMachine()->create(['vmid' => 101]))
        ->toThrow(ConnectionException::class);
});

it('validates node names before creating a VM', function (string $node) {
    expect(fn () => Proxmox::node($node)->virtualMachine()->create(['vmid' => 101]))
        ->toThrow(InvalidArgumentException::class, 'node');

    Http::assertNothingSent();
})->with(['', ' ', '.', '..', '../other', 'my/node', 'my\\node']);

it('keeps individual VM scopes independent and accepts integer or string identifiers', function () {
    Http::fake([
        '*/nodes/my/qemu/101/status/current' => Http::response(['data' => ['vmid' => 101]]),
        '*/nodes/other/qemu/201/status/current' => Http::response(['data' => ['vmid' => 201]]),
    ]);

    $first = Proxmox::node('my')->vm('101');
    $second = Proxmox::node('other')->vm(201);

    Http::assertNothingSent();

    expect($first->get())->toBe(['vmid' => 101])
        ->and($second->get())->toBe(['vmid' => 201])
        ->and($first->get())->toBe(['vmid' => 101]);

    Http::assertSentCount(3);
});

it('rejects invalid VM identifiers before sending a request', function (int|string $vmid) {
    expect(fn () => Proxmox::node('my')->vm($vmid)->get())
        ->toThrow(InvalidArgumentException::class, 'VM ID');

    expect(fn () => Proxmox::node('my')->virtualMachine($vmid)->first())
        ->toThrow(InvalidArgumentException::class, 'VM ID');

    Http::assertNothingSent();
})->with([0, -1, '', ' ', '0', '-1', '0101', '1.5', '../101', '101/config', '101?x=1', '101#fragment']);

it('validates node names for individual resources', function (bool $vms) {
    $resource = $vms ? Proxmox::node('../other')->vm(101) : Proxmox::nodes('../other');

    expect(fn () => $resource->get())->toThrow(InvalidArgumentException::class, 'node');

    Http::assertNothingSent();
})->with([false, true]);

it('encodes node names in individual resource paths', function (bool $vms) {
    Http::fake(['*' => Http::response(['data' => ['uptime' => 100]])]);

    $node = Proxmox::node(' my?node#name ');
    ($vms ? $node->vm(101) : $node)->get();

    Http::assertSent(fn (Request $request): bool => $request->url()
        === 'https://proxmox.example.test:8006/api2/json/nodes/my%3Fnode%23name/'
            .($vms ? 'qemu/101/status/current' : 'status'));
})->with([false, true]);

it('automatically throws for unsuccessful individual resource requests', function (int $status, bool $vms) {
    Http::fake(['*' => Http::response(['errors' => ['permission' => 'denied']], $status)]);

    $resource = $vms ? Proxmox::node('my')->vm(101) : Proxmox::node('my');

    expect(fn () => $resource->get())->toThrow(RequestException::class, (string) $status);
})->with([401, 403, 404, 500])->with([false, true]);

it('propagates connection failures from individual resources', function (bool $vms) {
    Http::fake(['*' => Http::failedConnection()]);

    $resource = $vms ? Proxmox::node('my')->vm(101) : Proxmox::node('my');

    expect(fn () => $resource->first())->toThrow(ConnectionException::class);
})->with([false, true]);

it('rejects malformed individual resource data', function (array|string $body, bool $vms) {
    Http::fake(['*' => Http::response($body)]);

    $resource = $vms ? Proxmox::node('my')->vm(101) : Proxmox::node('my');

    expect(fn () => $resource->get())->toThrow(UnexpectedValueException::class, 'data');
})->with([
    'missing data' => [[]],
    'null data' => [['data' => null]],
    'scalar data' => [['data' => 'invalid']],
    'list instead of attributes' => [['data' => [['vmid' => 101]]]],
    'empty list' => [['data' => []]],
    'invalid JSON' => ['{"data":'],
])->with([false, true]);
