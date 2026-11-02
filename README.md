<div align="center">
    <h1>Proxmox</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/aboutnima/proxmox"><img src="https://img.shields.io/packagist/v/aboutnima/proxmox.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/aboutnima/proxmox"><img src="https://img.shields.io/packagist/php-v/aboutnima/proxmox.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/aboutnima/proxmox"><img src="https://badge.laravel.cloud/badge/aboutnima/proxmox?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/aboutnima/proxmox/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/aboutnima/proxmox/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/aboutnima/proxmox"><img src="https://img.shields.io/packagist/dt/aboutnima/proxmox.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A Laravel package for integrating with the Proxmox REST API.

## Installation

You can install the package via Composer:

```bash
composer require aboutnima/proxmox
```

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="proxmox-config"
```

## Configuration

Configure an API token in your application's `.env` file. `PROXMOX_URL` should include the `/api2/json` path:

```dotenv
PROXMOX_URL=https://proxmox.example.com:8006/api2/json
PROXMOX_API_TOKEN_ID=laravel@pam!app
PROXMOX_API_TOKEN_SECRET=your-token-secret
```

| Variable | Default | Description |
|---|---|---|
| `PROXMOX_CONNECTION` | `default` | Connection used by the facade and by resolving `Proxmox\Proxmox`. |
| `PROXMOX_TIMEOUT` | `30` | Seconds allowed for each HTTP request. |
| `PROXMOX_CONNECT_TIMEOUT` | `10` | Seconds allowed to open the connection. |
| `PROXMOX_VERIFY_SSL` | `true` | TLS certificate verification. Prefer a trusted certificate over disabling it. |
| `PROXMOX_RETRY_TIMES` | `0` | Extra attempts for **GET** requests after a connection failure. Writes and HTTP error responses are never retried. |
| `PROXMOX_RETRY_SLEEP` | `100` | Milliseconds between GET retries. |

Configuration is validated lazily, so an incomplete configuration throws `Proxmox\Exceptions\InvalidConfigurationException` on the first request rather than when the application boots.

### Multiple Connections

Add more entries under `connections` in the published `config/proxmox.php`, then select them by name. Each connection is resolved once and reused:

```php
use Proxmox\Facades\Proxmox;

$nodes = Proxmox::connection('backup')->nodes()->all();
```

Calls without `connection()` use the default connection. `php artisan about` lists the configured connections without secrets.

## Usage

### Nodes and Virtual Machines

```php
use Proxmox\Facades\Proxmox;

$nodes = Proxmox::nodes()->all();

$onlineNode = Proxmox::nodes()->where('status', 'online')->first();

$vms = Proxmox::node('pve1')->virtualMachines()->all();

$runningVm = Proxmox::node('pve1')->virtualMachines()
    ->where('status', 'running')
    ->where('maxmem', '>=', 2147483648)
    ->first();
```

Virtual machine listings are scoped to the selected node with `node($name)->virtualMachines()`.

Listings return a Laravel collection of attribute arrays from `all()` (`get()` is an alias). `first()` returns the first matching array or `null`. Queries are lazy and immutable: `where()`, `whereIn()`, and `whereNotIn()` return a new query, and filters run locally with Laravel collection comparisons rather than as API parameters. Supported `where()` operators are `=`, `==`, `===`, `!=`, `<>`, `!==`, `<`, `>`, `<=`, and `>=`.

Common read-only collection methods can be called directly on a query. Each call fetches the listing once:

```php
$count = Proxmox::nodes()->count();
$names = Proxmox::nodes()->pluck('node');
$memory = Proxmox::node('pve1')->virtualMachines()->sum('maxmem');

$vms = Proxmox::node('pve1')->virtualMachines()
    ->when($onlyRunning, fn ($query) => $query->where('status', 'running'))
    ->whereIn('vmid', [101, 102])
    ->all();
```

The forwarded methods are `avg`, `contains`, `count`, `doesntContain`, `each`, `every`, `firstWhere`, `groupBy`, `isEmpty`, `isNotEmpty`, `keyBy`, `map`, `max`, `min`, `pluck`, `sole`, `sortBy`, `sortByDesc`, and `sum`. Register reusable filters as macros, for example in a service provider. Macros are shared by every listing:

```php
use Proxmox\Support\ResourceQuery;

ResourceQuery::macro('running', function () {
    return $this->where('status', 'running');
});

$running = Proxmox::node('pve1')->virtualMachines()->running()->all();
```

### Individual Resources

Select a single node or VM when you know its name or ID:

```php
$node = Proxmox::node('pve1')->status();

$vm = Proxmox::node('pve1')->virtualMachine(101);

$status = $vm->status(); // GET /nodes/{node}/qemu/{vmid}/status/current
$config = $vm->config(); // GET /nodes/{node}/qemu/{vmid}/config

$task = $vm->resize('scsi0', '+10G'); // Absolute ("32G") or relative ("+10G")
```

`status()` returns an unwrapped attribute array; `get()` and `first()` are aliases. Single resources have no `all()` or `where()`, and HTTP 404 responses throw instead of returning `null`. Node names and VM IDs are validated as soon as they are selected. VM IDs must be integers (or integer strings) between 100 and 999999999.

`Proxmox::nodes('pve1')` remains available as an alias for `Proxmox::node('pve1')`.

### Create a QEMU Virtual Machine

Build a VM fluently and call `create()`. Ask Proxmox for a free ID with `nextVirtualMachineId()`:

```php
$task = Proxmox::node('pve1')->virtualMachines()
    ->make(Proxmox::nextVirtualMachineId())
    ->name('web-101')
    ->sockets(1)
    ->cores(2)
    ->memory(4096)       // MiB
    ->storage('local-lvm')
    ->disk(32)           // GiB
    ->bridge('vmbr0')
    ->ip('192.0.2.10', prefix: 24, gateway: '192.0.2.1')
    ->user('ubuntu')
    ->sshKey($publicKey)
    ->with(['ostype' => 'l26', 'onboot' => 1])
    ->create();

$task->wait(); // Optional: block until Proxmox finishes the creation task.
```

The builder is `Conditionable` (`when()` / `unless()`), and `with()` merges any native QEMU parameter. Use `dhcp()` instead of `ip()` for DHCP, `password()` for a guest password, `image()` to import a cloud image, and `start()` to boot after creation. Passing an array to `create()` is equivalent:

```php
$task = Proxmox::node('pve1')->virtualMachines()->create([
    'vmid' => 101,
    'name' => 'web-101',
    'sockets' => 1,
    'cores' => 2,
    'memory' => 4096,
    'disk' => 32,
    'storage' => 'local-lvm',
    'bridge' => 'vmbr0',
    'ip' => '192.0.2.10',
    'prefix' => 24,
    'gateway' => '192.0.2.1',
]);
```

`sockets` sets the number of CPU sockets and `cores` sets cores per socket (total vCPUs = sockets x cores). Replace `storage`, `bridge`, and the example network addresses with your host's actual values. The package does not guess the subnet prefix or gateway.

| Builder method / field | Proxmox configuration |
|---|---|
| `disk()` + `storage()` | Allocates `scsi0` on that storage, using `virtio-scsi-pci` unless you supply `scsihw`. |
| `bridge()` | Creates `net0`, a VirtIO network card connected to the named bridge. |
| `ip()` (`ip` + `prefix` + optional `gateway`) | Builds the IPv4 Cloud-Init configuration in `ipconfig0`. `dhcp()` sets `ip=dhcp`. |
| `user()` / `password()` | Sets the guest's Cloud-Init `ciuser` / `cipassword`, not Proxmox API credentials. |
| `sshKey()` (`ssh_key`) | Sets Cloud-Init `sshkeys` from unencoded OpenSSH **public** keys. Pass several keys as separate arguments; the package handles the encoding. |
| `image()` + `storage()` | Imports a QCOW2 source into a new `scsi0` disk on the destination storage. |
| Any of `image`, `ip`, `user`, `password`, `ssh_key` + `storage` | Automatically attaches the Cloud-Init drive as `ide2`. |

For a static IPv4 address, supply a plain address (without `/24`), an integer prefix between 0 and 32, and optionally a gateway. An IP requires `storage` and either `bridge` or an explicit native `net0` card.

Native QEMU parameters remain supported, including additional disks, VLAN options, and IPv6 through `ipconfig0`. Do not combine `disk`/`image` with `scsi0`, `bridge` with `net0`, `ip` with `ipconfig0`, or credential aliases with their native equivalents. Readable Cloud-Init fields also conflict with an explicit `ide2`. Invalid readable fields and conflicts throw `InvalidArgumentException` before any request is sent. Native `sshkeys` must already be URL-encoded; only `ssh_key` performs that encoding. `Proxmox::createVirtualMachine('pve1', $configuration)` is the direct equivalent.

`create()` returns a `Proxmox\Tasks\Task` for the asynchronous creation, not a running VM. IP and guest credentials require a Cloud-Init-enabled image; attaching the drive alone does not configure an arbitrary OS or allocate a public IP. Without `image`, the disk is blank: hardware creation does not install an OS or clone a template.

### Tasks

Write operations in Proxmox run as background tasks identified by a UPID. Methods that start a task return a `Task`:

```php
$task->upid;          // "UPID:pve1:..."
$task->node;          // "pve1"
$task->status();      // GET /nodes/{node}/tasks/{upid}/status
$task->isRunning();
$task->log(start: 0, limit: 50);

$task->wait(timeout: 300, interval: 1);
```

`wait()` polls until the task stops. It returns the task when the exit status is `OK` or `WARNINGS: n`, and throws `Proxmox\Exceptions\TaskFailedException` for any other exit status or `Proxmox\Exceptions\TaskTimeoutException` when the timeout passes. A timeout does not cancel the remote task. Rebuild a task from a stored UPID with `Proxmox::task($upid)`, which reads the node from the UPID, or with `Proxmox::node('pve1')->task($upid)`. A `Task` casts to its UPID string.

### List and Create from QCOW2 Cloud Images

The source image must already exist **on Proxmox storage accessible from the selected node**, not on the Laravel server. Use a Proxmox version and file-based source storage that support the `import` content type. Enable **Import** in that storage's content settings, then place the QCOW2 in its `import/` directory. For the default `local` directory storage, that is `/var/lib/vz/import/` on the Proxmox host. `local-lvm` can hold the destination VM disk, but cannot hold import-source files.

List available QCOW2 import sources and select their `volid`:

```php
$images = Proxmox::node('pve1')->images('local')->all();

$image = Proxmox::node('pve1')->images('local')
    ->where('volid', 'local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2')
    ->first();
```

Image queries support the same query methods as VM listings. `Proxmox::getImages('pve1', 'local')` is the direct equivalent. These list QCOW2 files from `GET /nodes/{node}/storage/{storage}/content?content=import`, not existing VM disks or ISOs. An empty listing means no matching files; HTTP/permission errors still throw. The listing cannot verify that a file contains Cloud-Init.

```php
$task = Proxmox::node('pve1')->virtualMachines()
    ->make(102)
    ->name('ubuntu-102')
    ->cores(2)
    ->memory(4096)
    ->image('local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2')
    ->storage('local-lvm')
    ->disk(32)
    ->bridge('vmbr0')
    ->ip('192.0.2.10', prefix: 24, gateway: '192.0.2.1')
    ->user('ubuntu')
    ->sshKey($publicKey) // Contents of an OpenSSH .pub file, not its path.
    ->create(importTimeout: 300);
```

`image()` takes a volume ID such as `local:import/ubuntu.qcow2`, not a bare filename, absolute path, or download URL. Proxmox copies the source into a new per-VM disk using `import-from`; the reusable source is not attached as a shared writable disk. The package defaults to `virtio-scsi-pci`, booting from `scsi0`, and a serial console (`serial0=socket`, `vga=serial0`). Explicit native controller, boot, and console choices are preserved.

Without `disk`, creation returns the import task immediately and retains the image's virtual disk size. With `image` **and** `disk`, the method waits for successful import (up to `importTimeout` seconds, 300 by default), then submits a separate absolute-size resize and returns the **resize task**. Wait for that final task before starting the VM. Proxmox cannot shrink disks: request at least the source image's virtual size, which can differ from its compressed file size. Guest partition/filesystem expansion depends on the image's Cloud-Init configuration.

Run image provisioning with a requested disk size in a queued job rather than a short web request. `PROXMOX_TIMEOUT` still controls each HTTP request; `importTimeout` controls the import polling budget. An import failure or timeout throws `TaskFailedException` or `TaskTimeoutException` without submitting a resize. A VM may already exist after a later error; inspect Proxmox before retrying. No automatic deletion or retry is performed. `start` cannot be enabled with `image` and `disk`, because the VM must not boot before resizing finishes.

Grant the API token access to the source storage, VM allocation, destination storage allocation, network bridge, and `VM.Config.Disk` for resizing. Passwords and keys are guest credentials, not API-token credentials; use only public keys and keep passwords out of committed code. Password-based SSH access also depends on the guest image's SSH policy.

See the [Cloud-Init guide](https://pve.proxmox.com/wiki/Cloud-Init_Support), [storage content types](https://pve.proxmox.com/pve-docs/chapter-pvesm.html#_common_storage_properties), and [API Viewer](https://pve.proxmox.com/pve-docs/api-viewer/index.html) for the create, storage-content, task-status, and resize endpoints.

### Errors

All resource methods remove the API's `data` envelope. HTTP 4xx/5xx responses raise Laravel's `RequestException`, and connection failures raise `ConnectionException`. Package exceptions implement `Proxmox\Exceptions\ProxmoxException`:

| Exception | Thrown when |
|---|---|
| `InvalidConfigurationException` (`LogicException`) | A connection is missing or misconfigured. |
| `UnexpectedResponseException` (`UnexpectedValueException`) | Proxmox returns malformed data. |
| `TaskFailedException` | A waited task stops with an error. Exposes `upid` and `exitStatus`. |
| `TaskTimeoutException` | A waited task is still running after the timeout. Exposes `upid` and `timeout`. |

### Direct Shortcuts

`getNodes()`, `getNode($node)`, `getVirtualMachines($node)`, `getVirtualMachine($node, $vmid)`, `getImages($node, $storage)`, and `createVirtualMachine($node, $configuration)` remain available and return the same values as their fluent equivalents.

### Raw HTTP Requests

The generic `get()`, `post()`, `put()`, `delete()`, and `request()` methods return Laravel HTTP responses for custom endpoints, or when you need headers and status codes. For these raw methods, call `throw()` explicitly to raise HTTP errors. GET and DELETE parameters are sent as query parameters, because Proxmox ignores DELETE request bodies. POST and PUT parameters are sent as form data:

```php
$response = Proxmox::get('nodes');

Proxmox::delete('nodes/pve1/qemu/101', ['purge' => 1])->throw();
```

### Testing

Fake the Laravel HTTP client in your application tests:

```php
use Illuminate\Support\Facades\Http;

Http::fake(['*/nodes/pve1/qemu' => Http::response(['data' => [['vmid' => 101]]])]);

expect(Proxmox::node('pve1')->virtualMachines()->pluck('vmid')->all())->toBe([101]);
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Proxmox! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

### Source Structure

Related classes are grouped by domain, following Laravel's component organization, such as [Mail](https://github.com/laravel/framework/tree/13.x/src/Illuminate/Mail) and [Bus](https://github.com/laravel/framework/tree/13.x/src/Illuminate/Bus). Shared exceptions and support code have dedicated namespaces:

```text
src/
├── Nodes/
│   ├── Node.php
│   └── Nodes.php
├── VirtualMachines/
│   ├── VirtualMachine.php
│   ├── VirtualMachines.php
│   ├── PendingVirtualMachine.php
│   └── VirtualMachineConfiguration.php
├── Images/
│   └── Images.php
├── Tasks/
│   └── Task.php
├── Exceptions/
├── Support/
│   ├── Identifier.php
│   ├── ResourceQuery.php
│   └── ResponseData.php
├── Facades/
├── Proxmox.php
├── ProxmoxManager.php
└── ProxmoxServiceProvider.php
```

Direct resource imports use `Proxmox\Nodes`, `Proxmox\VirtualMachines`, `Proxmox\Images`, and `Proxmox\Tasks`. For example, a task is `Proxmox\Tasks\Task`. Shared listing macros use `Proxmox\Support\ResourceQuery`; package exceptions remain under `Proxmox\Exceptions`.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Nima Asaadi](https://github.com/aboutnima)
- [All Contributors](../../contributors)

## License

Proxmox is open-sourced software licensed under the [MIT license](LICENSE.md).
