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

$nodes = Proxmox::connection('backup')->nodes();
```

Calls without `connection()` use the default connection. `php artisan about` lists the configured connections without secrets.

## Usage

### Nodes and Virtual Machines

Listing methods fetch immediately and return plain arrays:

```php
use Proxmox\Facades\Proxmox;

$nodes = Proxmox::nodes();

$node = Proxmox::node('pve1');
$vms = $node->virtualMachines()->all();
```

`nodes()` lists nodes. `node($name)` selects a node context without sending a request. `virtualMachines()` returns node-scoped VM operations without making a request; `all()` fetches the listing as an array. An empty listing returns `[]`.

Resource results sort associative keys alphabetically at every nesting level. VM listings, status, configuration, stored snapshots, and JSON therefore have consistent key ordering. List item order and field values are preserved.

Use Laravel collections when you need to filter, sort, or aggregate the fetched data. These operations reuse the data without further API requests:

```php
$onlineNode = collect($nodes)->where('status', 'online')->first();

$runningVm = collect($vms)
    ->where('status', 'running')
    ->where('maxmem', '>=', 2147483648)
    ->first();

$count = count($vms);
$names = collect($vms)->pluck('name');
$memory = collect($vms)->sum('maxmem');
```

### Individual Resources

Fetch node status or select a VM by its ID:

```php
$node = Proxmox::node('pve1');
$nodeStatus = $node->status();

$vm = $node->virtualMachine(101); // Fetches /nodes/pve1/qemu/101/status/current.

$data = $vm->toArray();          // Stored runtime data; no request.
$response = response()->json($vm); // Serializes the same data; no request.

$config = $vm->config();         // Fetches /nodes/pve1/qemu/101/config.
```

`virtualMachine($id)` immediately fetches runtime status and returns a `Proxmox\Resources\VirtualMachines\VirtualMachine` object containing that data and its management methods. `toArray()` and JSON serialization return only the stored data, without sending requests. Listing rows are summary arrays; fetch a VM by ID when you need its runtime data or management methods.

Read fresh status or update the stored snapshot explicitly:

```php
$currentStatus = $vm->status(); // Fresh array; leaves the stored data unchanged.
$vm->refresh();                 // Fetches fresh data, updates the snapshot, returns $vm.
```

HTTP 404 responses throw when selecting a VM. Node names and VM IDs are validated before requesting their data. VM IDs must be integers (or integer strings) between 100 and 999999999.

### Change CPU, Memory, and Disk Size

Each method sends one change and returns a `Task`:

```php
$vm = Proxmox::node('pve1')->virtualMachine(101);

$vm->setCpu(cores: 4, sockets: 1)->wait(); // Cores per socket; omit sockets to keep the current count.
$vm->setMemory(8192)->wait();              // MiB
$vm->growDisk('scsi0', 10)->wait();        // Add 10 GiB to scsi0.

$vm->refresh(); // Update the stored snapshot after the changes are applied.
```

`setCpu()` and `setMemory()` use Proxmox's asynchronous `POST /nodes/{node}/qemu/{vmid}/config` endpoint. Unless CPU or memory hotplug is enabled, Proxmox can keep these changes pending for a running VM until it restarts.

`growDisk()` increases an existing disk, such as `scsi0` or `virtio1`, by the given number of GiB. It does not attach a new disk. Proxmox cannot shrink disks, and the guest may still need to expand its partition and filesystem. All values must be positive integers.

### Create a QEMU Virtual Machine

Pass a configuration array to `node($name)->virtualMachines()->create()`. Its PHPDoc declares the supported readable keys and common native keys for IDE completion. `vmid` is required; request a free ID with `nextVirtualMachineId()`:

```php
$task = Proxmox::node('pve1')->virtualMachines()->create([
    'vmid' => Proxmox::nextVirtualMachineId(),
    'name' => 'web-server',
    'sockets' => 1,
    'cores' => 2,
    'memory' => 4096,       // MiB
    'storage' => 'local-lvm',
    'disk' => 32,           // GiB
    'bridge' => 'vmbr0',
    'ip' => '192.0.2.10',
    'prefix' => 24,
    'gateway' => '192.0.2.1',
    'user' => 'ubuntu',
    'ssh_key' => $publicKey,
    'ostype' => 'l26',
    'onboot' => 1,
]);

$task->wait(); // Optional: block until Proxmox finishes the creation task.
```

`sockets` sets CPU sockets and `cores` sets cores per socket (total vCPUs = sockets x cores). Replace `storage`, `bridge`, and the example network addresses with your host's actual values. The package does not guess the subnet prefix or gateway.

| Field | Proxmox configuration |
|---|---|
| `disk` + `storage` | Allocates `scsi0` on that storage, using `virtio-scsi-pci` unless you supply `scsihw`. |
| `bridge` | Creates `net0`, a VirtIO network card connected to the named bridge. |
| `ip` + `prefix` + optional `gateway` | Builds the IPv4 Cloud-Init configuration in `ipconfig0`. Set `ip` to `dhcp` for DHCP. |
| `user` / `password` | Sets the guest's Cloud-Init `ciuser` / `cipassword`. |
| `ssh_key` | Sets Cloud-Init `sshkeys` from unencoded OpenSSH public key text. Separate multiple keys with newlines; the package encodes the string. |
| `image` + `storage` | Imports a QCOW2 source into a new `scsi0` disk on the destination storage. |
| Any of `image`, `ip`, `user`, `password`, `ssh_key` + `storage` | Automatically attaches the Cloud-Init drive as `ide2`. |
| `start` | Boots after creation when enabled. Cannot be enabled together with `image` and `disk`. |

For a static IPv4 address, supply a plain address (without `/24`), an integer prefix between 0 and 32, and optionally a gateway. An IP requires `storage` and either `bridge` or an explicit native `net0` card.

Additional native QEMU parameters remain supported, including additional disks, VLAN options, and IPv6 through `ipconfig0`. The package passes these extra keys through to Proxmox. Do not combine `disk`/`image` with `scsi0`, `bridge` with `net0`, `ip` with `ipconfig0`, or readable guest credentials with their native equivalents. Readable Cloud-Init fields also conflict with an explicit `ide2`. Invalid readable fields, invalid VM IDs, and conflicts throw `InvalidArgumentException` before any request is sent. Native `sshkeys` must already be URL-encoded; only `ssh_key` performs that encoding.

`create()` returns a `Proxmox\Resources\Tasks\Task` for the asynchronous creation. IP and guest credentials require a Cloud-Init-enabled image; attaching the drive alone does not configure an arbitrary OS or allocate a public IP. Without `image`, the disk is blank: hardware creation does not install an OS or clone a template.

### Tasks

Write operations in Proxmox run as background tasks identified by a UPID. Methods that start a task return a `Task`:

```php
$task->upid;          // "UPID:pve1:..."
$task->node;          // "pve1"
$task->status();      // GET /nodes/{node}/tasks/{upid}/status
$task->isRunning();
$task->log(start: 0, limit: 50); // Array of log lines.

$task->wait(timeout: 300, interval: 1);
```

`wait()` polls until the task stops. It returns the task when the exit status is `OK` or `WARNINGS: n`, and throws `Proxmox\Exceptions\TaskFailedException` for any other exit status or `Proxmox\Exceptions\TaskTimeoutException` when the timeout passes. A timeout does not cancel the remote task. Rebuild a task from a stored UPID with `Proxmox::task($upid)`, which reads the node from the UPID, or with `Proxmox::node('pve1')->task($upid)`. A `Task` casts to its UPID string.

### List and Create from QCOW2 Cloud Images

The source image must already exist **on Proxmox storage accessible from the selected node**, not on the Laravel server. Use a Proxmox version and file-based source storage that support the `import` content type. Enable **Import** in that storage's content settings, then place the QCOW2 in its `import/` directory. For the default `local` directory storage, that is `/var/lib/vz/import/` on the Proxmox host. `local-lvm` can hold the destination VM disk, but cannot hold import-source files.

List available QCOW2 import sources and select their `volid`:

```php
$images = Proxmox::node('pve1')->images('local');

$image = collect($images)
    ->where('volid', 'local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2')
    ->first();
```

`images($storage)` fetches immediately and returns an array of QCOW2 files from `GET /nodes/{node}/storage/{storage}/content?content=import`, not existing VM disks or ISOs. An empty listing means no matching files; HTTP/permission errors still throw. The listing cannot verify that a file contains Cloud-Init.

```php
$task = Proxmox::node('pve1')->virtualMachines()->create([
    'vmid' => 102,
    'name' => 'ubuntu-102',
    'cores' => 2,
    'memory' => 4096,
    'image' => 'local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2',
    'storage' => 'local-lvm',
    'disk' => 32,
    'bridge' => 'vmbr0',
    'ip' => '192.0.2.10',
    'prefix' => 24,
    'gateway' => '192.0.2.1',
    'user' => 'ubuntu',
    'ssh_key' => $publicKey, // Contents of an OpenSSH .pub file.
], importTimeout: 300);
```

The `image` field takes a volume ID such as `local:import/ubuntu.qcow2`, not a bare filename, absolute path, or download URL. Proxmox copies the source into a new per-VM disk using `import-from`; the reusable source is not attached as a shared writable disk. The package defaults to `virtio-scsi-pci`, booting from `scsi0`, and a serial console (`serial0=socket`, `vga=serial0`). Explicit native controller, boot, and console choices are preserved.

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

### Migrating to the Direct-Fetch API

`nodes()`, `images($storage)`, and task logs return arrays directly. Fetch VMs through `virtualMachines()->all()`, which also returns an array. Use `collect($data)` for local filtering; VM operations provide `all()` and `create()` without query methods or macros.

| Previous call | Replacement |
|---|---|
| `Proxmox::getNodes()` | `Proxmox::nodes()` |
| `Proxmox::getNode($node)` | `Proxmox::node($node)->status()` |
| `Proxmox::getVirtualMachines($node)` | `Proxmox::node($node)->virtualMachines()->all()` |
| `Proxmox::getVirtualMachine($node, $id)` | `Proxmox::node($node)->virtualMachine($id)->toArray()` |
| `Proxmox::getImages($node, $storage)` | `Proxmox::node($node)->images($storage)` |
| `Proxmox::createVirtualMachine($node, $config)` | `Proxmox::node($node)->virtualMachines()->create($config)` |
| `Proxmox::nodes($node)` | `Proxmox::node($node)` |
| `node()->virtualMachine()` | `node()->virtualMachines()->all()` |
| `node()->createVirtualMachine($config)` | `node()->virtualMachines()->create($config)` |
| `node()->makeVirtualMachine($id)->...->create()` | `node()->virtualMachines()->create(['vmid' => $id, ...])` |

Node and VM `get()` / `first()` aliases have been removed. Use `node()->status()` for node data, `virtualMachine($id)->toArray()` for a VM's fetched data, and `virtualMachine($id)->status()` for a fresh status request. `Task::log()` now returns an array as well.

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

expect(Proxmox::node('pve1')->virtualMachines()->all())->toBe([['vmid' => 101]]);
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Proxmox! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

### Source Structure

Related resources are grouped by domain under `Proxmox\Resources`. Shared exceptions and support code have dedicated namespaces:

```text
src/
├── Resources/
│   ├── Nodes/
│   │   └── Node.php
│   ├── VirtualMachines/
│   │   ├── VirtualMachine.php
│   │   ├── VirtualMachines.php
│   │   └── VirtualMachineConfiguration.php
│   └── Tasks/
│       └── Task.php
├── Exceptions/
├── Support/
│   ├── Identifier.php
│   └── ResponseData.php
├── Facades/
├── Proxmox.php
├── ProxmoxManager.php
└── ProxmoxServiceProvider.php
```

For example, direct imports use `Proxmox\Resources\VirtualMachines\VirtualMachine` and `Proxmox\Resources\Tasks\Task`. Package exceptions live under `Proxmox\Exceptions`.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Nima Asaadi](https://github.com/aboutnima)
- [All Contributors](../../contributors)

## License

Proxmox is open-sourced software licensed under the [MIT license](LICENSE.md).
