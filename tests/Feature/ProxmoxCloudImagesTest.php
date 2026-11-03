<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Proxmox\Facades\Proxmox;
use Proxmox\Resources\Tasks\Task;

beforeEach(function () {
    config()->set([
        'proxmox.connections.default.url' => 'https://proxmox.example.test:8006/api2/json',
        'proxmox.connections.default.api_token_id' => 'laravel@pam!package',
        'proxmox.connections.default.api_token_secret' => 'token-secret',
    ]);

    Http::preventStrayRequests();
    Sleep::fake(syncWithCarbon: true);
});

afterEach(function () {
    Sleep::fake(false);
    Carbon::setTestNow();
});

it('lists importable QCOW2 images as arrays through the facade and client', function (bool $direct) {
    $image = ['format' => 'qcow2', 'size' => 3758096384, 'volid' => 'local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2'];

    Http::fake(['*' => Http::response(['data' => [
        $image,
        ['volid' => 'local:import/appliance.ova', 'format' => 'ova'],
    ]])]);

    $images = $direct
        ? Proxmox::connection()->node('my')->images('local')
        : Proxmox::node('my')->images('local');

    expect($images)->toBeArray()->toBe([$image]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/storage/local/content?content=import'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pam!package=token-secret'));
    Http::assertSentCount(1);
})->with([false, true]);

it('fetches image arrays scoped to each storage for consumer filtering', function () {
    $image = ['format' => 'qcow2', 'volid' => 'local:import/ubuntu.qcow2'];

    Http::fake([
        '*/storage/local/*' => Http::response(['data' => [$image]]),
        '*/storage/other/*' => Http::response(['data' => []]),
    ]);

    $images = Proxmox::node('my')->images('local');
    $items = collect($images);

    expect($images)->toBe([$image])
        ->and($items->where('volid', $image['volid'])->first())->toBe($image)
        ->and($items->where('volid', 'local:import/missing.qcow2')->first())->toBeNull()
        ->and(Proxmox::node('my')->images('other'))->toBe([]);

    Http::assertSentCount(2);
});

it('rejects invalid image storage identifiers before requesting content', function (string $storage) {
    expect(fn () => Proxmox::node('my')->images($storage))
        ->toThrow(InvalidArgumentException::class, 'storage');

    Http::assertNothingSent();
})->with(['', ' ', '../local', 'local/path', 'local?content=images', 'local\\other', "local\n"]);

it('validates the node for image listings', function () {
    expect(fn () => Proxmox::node('../my')->images('local'))
        ->toThrow(InvalidArgumentException::class, 'node');

    Http::assertNothingSent();
});

it('throws HTTP and connection failures from image listings', function (bool $connection) {
    Http::fake(['*' => $connection ? Http::failedConnection() : Http::response([], 403)]);

    expect(fn () => Proxmox::node('my')->images('local'))
        ->toThrow($connection ? ConnectionException::class : RequestException::class);
})->with([false, true]);

it('rejects malformed image listing envelopes', function (array $body) {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => Proxmox::node('my')->images('local'))
        ->toThrow(UnexpectedValueException::class);
})->with([
    'missing data' => [[]],
    'null data' => [['data' => null]],
    'non-list data' => [['data' => ['volid' => 'local:import/ubuntu.qcow2']]],
    'non-array item' => [['data' => ['invalid']]],
    'missing image format' => [['data' => [['volid' => 'local:import/ubuntu.qcow2']]]],
    'missing volume identifier' => [['data' => [['format' => 'qcow2']]]],
    'empty volume identifier' => [['data' => [['volid' => '', 'format' => 'qcow2']]]],
]);

it('maps guest credentials and preserves CPU topology through facade and client creation', function (bool $direct) {
    $keys = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGZha2VrZXlmb3J0ZXN0c29ubHkwMDAwMDAwMDAw test+one@example.test\nssh-rsa AAAAB3NzaC1yc2EAAAADAQABAAABAQCTestKeyOnly test/two";
    $configuration = [
        'vmid' => 101,
        'sockets' => 2,
        'cores' => 4,
        'storage' => 'local-lvm',
        'user' => 'ubuntu',
        'password' => 'test-only p@ss+word&=!',
        'ssh_key' => $keys,
    ];

    Http::fake(['*' => Http::response(['data' => 'UPID:create'])]);

    $virtualMachines = $direct
        ? Proxmox::connection()->node('my')->virtualMachines()
        : Proxmox::node('my')->virtualMachines();
    $task = $virtualMachines->create($configuration);

    expect($task)->toBeInstanceOf(Task::class)
        ->and($task->upid)->toBe('UPID:create')
        ->and($task->node)->toBe('my')
        ->and($configuration['ssh_key'])->toBe($keys);

    Http::assertSent(function (Request $request) use ($keys): bool {
        parse_str($request->body(), $form);

        return $form === [
            'vmid' => '101',
            'sockets' => '2',
            'cores' => '4',
            'storage' => 'local-lvm',
            'ciuser' => 'ubuntu',
            'cipassword' => 'test-only p@ss+word&=!',
            'sshkeys' => rawurlencode($keys),
            'ide2' => 'local-lvm:cloudinit',
        ];
    });
    Http::assertSentCount(1);
})->with([false, true]);

it('adds a Cloud-Init drive for each readable guest credential independently', function (string $field, string $native) {
    Http::fake(['*' => Http::response(['data' => 'UPID:create'])]);

    Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101, 'storage' => 'local-lvm', $field => 'test-only',
    ]);

    Http::assertSent(fn (Request $request): bool => $request[$native] === 'test-only'
        && $request['ide2'] === 'local-lvm:cloudinit'
        && ! isset($request[$field]));
})->with([
    ['user', 'ciuser'],
    ['password', 'cipassword'],
    ['ssh_key', 'sshkeys'],
]);

it('leaves native guest credentials and encoded SSH keys unchanged', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:create'])]);

    $configuration = [
        'vmid' => 101,
        'ciuser' => 'ubuntu',
        'cipassword' => 'test-only',
        'sshkeys' => 'ssh-ed25519%20test%2Bkey',
        'ide2' => 'local-lvm:cloudinit',
    ];

    Proxmox::node('my')->virtualMachines()->create($configuration);

    Http::assertSent(fn (Request $request): bool => $request->data() === $configuration);
});

it('imports a selected image into a new boot disk with Cloud-Init and a serial console', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:import'])]);

    $task = Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101,
        'image' => 'local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2',
        'storage' => 'local-lvm',
        'bridge' => 'vmbr0',
        'ip' => 'dhcp',
        'user' => 'ubuntu',
    ]);

    expect($task->upid)->toBe('UPID:import');

    Http::assertSent(fn (Request $request): bool => $request->data() == [
        'vmid' => 101,
        'storage' => 'local-lvm',
        'scsi0' => 'local-lvm:0,import-from=local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2',
        'scsihw' => 'virtio-scsi-pci',
        'boot' => 'order=scsi0',
        'serial0' => 'socket',
        'vga' => 'serial0',
        'net0' => 'virtio,bridge=vmbr0',
        'ipconfig0' => 'ip=dhcp',
        'ciuser' => 'ubuntu',
        'ide2' => 'local-lvm:cloudinit',
    ]);
    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

it('preserves explicit native boot and console choices for imported images', function () {
    Http::fake(['*' => Http::response(['data' => 'UPID:import'])]);

    Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101,
        'image' => 'local:import/ubuntu.qcow2',
        'storage' => 'local-lvm',
        'boot' => 'order=scsi0;net0',
        'serial0' => '/dev/ttyS0',
        'vga' => 'std',
        'scsihw' => 'virtio-scsi-single',
    ]);

    Http::assertSent(fn (Request $request): bool => $request['boot'] === 'order=scsi0;net0'
        && $request['serial0'] === '/dev/ttyS0'
        && $request['vga'] === 'std'
        && $request['scsihw'] === 'virtio-scsi-single');
});

it('waits for successful import before requesting an absolute disk size', function (bool $direct) {
    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => 'UPID:import']),
        '*/tasks/UPID%3Aimport/status' => Http::sequence()
            ->push(['data' => ['status' => 'running']])
            ->push(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        '*/qemu/101/resize' => Http::response(['data' => 'UPID:resize']),
    ]);

    $configuration = ['vmid' => '101', 'image' => 'local:import/ubuntu.qcow2', 'storage' => 'local-lvm', 'disk' => 32];
    $virtualMachines = $direct
        ? Proxmox::connection()->node('my')->virtualMachines()
        : Proxmox::node('my')->virtualMachines();
    $task = $virtualMachines->create($configuration, importTimeout: 10);

    expect($task->upid)->toBe('UPID:resize');

    expect(Http::recorded()->map(fn (array $record): string => $record[0]->method())->all())
        ->toBe(['POST', 'GET', 'GET', 'PUT']);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request['scsi0'] === 'local-lvm:0,import-from=local:import/ubuntu.qcow2'
        && ! isset($request['disk']));
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu/101/resize'
        && $request->data() === ['disk' => 'scsi0', 'size' => '32G']);
    Http::assertSentCount(4);
    Sleep::assertSleptTimes(1);
})->with([false, true]);

it('propagates connection failures while polling or submitting the resize', function (bool $polling) {
    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => 'UPID:import']),
        '*/tasks/*' => $polling
            ? Http::failedConnection()
            : Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        '*/resize' => Http::failedConnection(),
    ]);

    expect(fn () => Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101, 'image' => 'local:import/ubuntu.qcow2', 'storage' => 'local-lvm', 'disk' => 32,
    ]))->toThrow(ConnectionException::class);
})->with([false, true]);

it('requires a valid task ID from the resize endpoint', function (mixed $task) {
    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => 'UPID:import']),
        '*/tasks/*' => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        '*/resize' => Http::response(['data' => $task]),
    ]);

    expect(fn () => Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101, 'image' => 'local:import/ubuntu.qcow2', 'storage' => 'local-lvm', 'disk' => 32,
    ]))->toThrow(UnexpectedValueException::class, 'task ID');
})->with([null, '', ' ', 101, [[]]]);

it('does not resize after unsuccessful or malformed import task status', function (array $body, string $exception) {
    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => 'UPID:import']),
        '*/tasks/*' => Http::response($body),
    ]);

    expect(fn () => Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101, 'image' => 'local:import/ubuntu.qcow2', 'storage' => 'local-lvm', 'disk' => 32,
    ]))->toThrow($exception);

    Http::assertSentCount(2);
})->with([
    'failed import' => [['data' => ['status' => 'stopped', 'exitstatus' => 'ERROR: import failed']], RuntimeException::class],
    'missing exit status' => [['data' => ['status' => 'stopped']], UnexpectedValueException::class],
    'unknown task status' => [['data' => ['status' => 'unknown']], UnexpectedValueException::class],
    'missing task data' => [[], UnexpectedValueException::class],
]);

it('times out without resizing or deleting a VM whose import is still running', function () {
    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => 'UPID:import']),
        '*/tasks/*' => Http::response(['data' => ['status' => 'running']]),
    ]);

    expect(fn () => Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101, 'image' => 'local:import/ubuntu.qcow2', 'storage' => 'local-lvm', 'disk' => 32,
    ], importTimeout: 1))->toThrow(RuntimeException::class, 'UPID:import');

    Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PUT', 'DELETE']));
    Sleep::assertSleptTimes(1);
});

it('propagates HTTP failures while polling or submitting the resize', function (bool $polling) {
    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => 'UPID:import']),
        '*/tasks/*' => $polling
            ? Http::response([], 403)
            : Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
        '*/resize' => Http::response([], 403),
    ]);

    expect(fn () => Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101, 'image' => 'local:import/ubuntu.qcow2', 'storage' => 'local-lvm', 'disk' => 32,
    ]))->toThrow(RequestException::class, '403');
})->with([false, true]);

it('rejects invalid readable cloud image settings before creation', function (array $invalid, string $field) {
    expect(fn () => Proxmox::node('my')->virtualMachines()->create(array_replace([
        'vmid' => 101, 'image' => 'local:import/ubuntu.qcow2', 'storage' => 'local-lvm',
    ], $invalid)))->toThrow(InvalidArgumentException::class, $field);

    Http::assertNothingSent();
})->with([
    'bare filename' => [['image' => 'ubuntu.qcow2'], 'image'],
    'host path' => [['image' => '/var/lib/vz/import/ubuntu.qcow2'], 'image'],
    'ISO source' => [['image' => 'local:iso/ubuntu.qcow2'], 'image'],
    'attached VM disk' => [['image' => 'local:101/vm-101-disk-0.qcow2'], 'image'],
    'image options' => [['image' => 'local:import/ubuntu.qcow2,discard=on'], 'image'],
    'image traversal' => [['image' => 'local:import/../ubuntu.qcow2'], 'image'],
    'null image' => [['image' => null], 'image'],
    'non-QCOW2 image' => [['image' => 'local:import/ubuntu.iso'], 'image'],
    'image disk conflict' => [['scsi0' => 'local-lvm:32'], 'scsi0'],
    'Cloud-Init conflict' => [['ide2' => 'local:iso/installer.iso'], 'ide2'],
    'null user' => [['user' => null], 'user'],
    'blank user' => [['user' => '  '], 'user'],
    'null password' => [['password' => null], 'password'],
    'empty password' => [['password' => ''], 'password'],
    'array key' => [['ssh_key' => []], 'ssh_key'],
    'empty key' => [['ssh_key' => ''], 'ssh_key'],
    'user conflict' => [['user' => 'ubuntu', 'ciuser' => 'root'], 'ciuser'],
    'password conflict' => [['password' => 'test-only', 'cipassword' => 'other'], 'cipassword'],
    'key conflict' => [['ssh_key' => 'ssh-ed25519 test-only', 'sshkeys' => 'other'], 'sshkeys'],
    'early start' => [['disk' => 32, 'start' => 1], 'start'],
    'invalid resize VM ID' => [['disk' => 32, 'vmid' => '../101'], 'VM ID'],
    'missing resize VM ID' => [['disk' => 32, 'vmid' => null], 'VM ID'],
]);

it('requires storage for guest credentials even without an image', function (string $field) {
    expect(fn () => Proxmox::node('my')->virtualMachines()->create(['vmid' => 101, $field => 'test-only']))
        ->toThrow(InvalidArgumentException::class, 'storage');

    Http::assertNothingSent();
})->with(['user', 'password', 'ssh_key']);

it('validates the import wait timeout before allocating a VM', function () {
    expect(fn () => Proxmox::node('my')->virtualMachines()->create([
        'vmid' => 101, 'image' => 'local:import/ubuntu.qcow2', 'storage' => 'local-lvm', 'disk' => 32,
    ], importTimeout: 0))->toThrow(InvalidArgumentException::class, 'importTimeout');

    Http::assertNothingSent();
});
