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

## Usage

Configure an API token in your application's `.env` file. `PROXMOX_URL` should include the `/api2/json` path:

```dotenv
PROXMOX_URL=https://proxmox.example.com:8006/api2/json
PROXMOX_API_TOKEN_ID=laravel@pam!app
PROXMOX_API_TOKEN_SECRET=your-token-secret
```

Use collection queries to list nodes and VMs, and chain `where()` to filter them:

```php
use Proxmox\Facades\Proxmox;

$nodes = Proxmox::nodes()->all();

$onlineNode = Proxmox::nodes()->where('status', 'online')->first();

$vms = Proxmox::node('my')->virtualMachine()->all();

$runningVm = Proxmox::node('my')->virtualMachine()
    ->where('status', 'running')
    ->where('maxmem', '>=', 2147483648)
    ->first();
```

The package uses the `Proxmox` root namespace. Update older facade imports to `Proxmox\Facades\Proxmox`; the Composer package name remains `aboutnima/proxmox`.

List `all()` returns a Laravel collection of attribute arrays; `get()` remains an alias. List `first()` returns the first matching array or `null` when nothing matches. An empty listing returns an empty collection.

Filters are lazy and applied locally using Laravel collection comparisons, not sent as API query parameters. Use `where('key', $value)` for equality or `where('key', '>=', $value)` for comparisons; chained filters are combined with AND. Supported operators are `=`, `==`, `===`, `!=`, `<>`, `!==`, `<`, `>`, `<=`, and `>=`. Each query terminal (`all()`, `get()`, or `first()`) makes a fresh request for the entire listing. Queries are immutable, so adding a filter does not change an existing query.

### Individual Resources

Prefer singular methods when you know the node name or VM ID:

```php
$node = Proxmox::node('my')->get();

$vm = Proxmox::node('my')->virtualMachine(101)->get();
```

These return unwrapped status attribute arrays from `/nodes/{node}/status` and `/nodes/{node}/qemu/{vmid}/status/current`, respectively, rather than a row from the listing. VM IDs may be positive integers or their string representations, such as `101` or `'101'`.

The same method accepts an ID to select one VM:

```php
$node = Proxmox::nodes('my')->first();

$vm = Proxmox::node('my')->virtualMachine('101')->first();
```

These selectors return dedicated single-resource objects without `all()` or `where()`. On a single resource, `first()` is an alias for `get()`; it does not search a list or turn HTTP 404 responses into `null`.

All resource requests remove the API's `data` envelope. HTTP 4xx/5xx responses automatically raise Laravel's `RequestException`, connection failures propagate, and malformed resource data raises `UnexpectedValueException`.

The direct shortcuts `Proxmox::getNodes()` and `Proxmox::getVirtualMachines('my')` now return the same collections instead of HTTP responses. Remove any existing `->throw()->json('data')` calls from these shortcuts.

The direct single-resource shortcuts `Proxmox::getNode('my')` and `Proxmox::getVirtualMachine('my', 101)` return the same status attribute arrays as their fluent counterparts.

### Create a QEMU Virtual Machine

Use readable fields for the disk and network instead of building Proxmox device strings:

```php
$task = Proxmox::node('my')->virtualMachine()->create([
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

`vmid` must be unique. `sockets` sets the number of CPU sockets and `cores` sets cores per socket (total vCPUs = sockets x cores). `memory` is RAM in MiB and `disk` is a positive integer size in GiB. Replace `storage`, `bridge`, and the example network addresses with your host's actual values. The package does not guess the subnet prefix or gateway.

| Readable field | Proxmox configuration |
|---|---|
| `disk` + `storage` | Allocates `scsi0` on that storage, using `virtio-scsi-pci` unless you supply `scsihw`. |
| `bridge` | Creates `net0`, a VirtIO network card connected to the named bridge. |
| `ip` + `prefix` + optional `gateway` | Builds the IPv4 Cloud-Init configuration in `ipconfig0`. |
| `user` / `password` | Sets the guest's Cloud-Init `ciuser` / `cipassword`, not Proxmox API credentials. |
| `ssh_key` | Sets Cloud-Init `sshkeys`; supply unencoded OpenSSH **public** key text, one key per line. The package handles the required encoding. |
| `image` + `storage` | Imports a QCOW2 source into a new `scsi0` disk on the destination storage. |
| Any of `image`, `ip`, `user`, `password`, `ssh_key` + `storage` | Automatically attaches the Cloud-Init drive as `ide2`. |

For static IPv4, supply a plain address (without `/24`), an integer `prefix` between 0 and 32, and optionally a `gateway`. For DHCP, use `'ip' => 'dhcp'` and omit `prefix` and `gateway`. An `ip` requires `storage` and either `bridge` or an explicit native `net0` card.

Native QEMU parameters remain supported, including additional disks, VLAN options, and IPv6 through `ipconfig0`. Do not combine `disk`/`image` with `scsi0`, `bridge` with `net0`, `ip` with `ipconfig0`, or credential aliases with their native equivalents. Readable Cloud-Init fields also conflict with an explicit `ide2`. Invalid readable fields and conflicts throw `InvalidArgumentException` before any request is sent. For a custom Cloud-Init drive or network configuration, use the native fields together. Native `sshkeys` must already be URL-encoded; only `ssh_key` performs that encoding. The same readable fields work with `Proxmox::createVirtualMachine('my', $configuration)`.

The method returns an asynchronous task UPID, not a running VM. IP and guest credentials require a Cloud-Init-enabled image; attaching the drive alone does not configure an arbitrary OS or allocate a public IP. Without `image`, the disk is blank: hardware creation does not install an OS or clone a template.

### List and Create from QCOW2 Cloud Images

The source image must already exist **on Proxmox storage accessible from the selected node**, not on the Laravel server. Use a Proxmox version and file-based source storage that support the `import` content type. Enable **Import** in that storage's content settings, then place the QCOW2 in its `import/` directory. For the default `local` directory storage, that is `/var/lib/vz/import/` on the Proxmox host. `local-lvm` can hold the destination VM disk, but cannot hold import-source files.

List available QCOW2 import sources and select their `volid`:

```php
$images = Proxmox::node('my')->images('local')->all();

$image = Proxmox::node('my')->images('local')
    ->where('volid', 'local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2')
    ->first();
```

Image queries support `all()`, `get()`, `where()`, and `first()` with the same collection semantics as VM queries. `Proxmox::getImages('my', 'local')` is the direct equivalent. These list QCOW2 files from `GET /nodes/{node}/storage/{storage}/content?content=import`, not existing VM disks or ISOs. An empty listing means no matching files; HTTP/permission errors still throw. The listing cannot verify that a file contains Cloud-Init.

```php
$task = Proxmox::node('my')->virtualMachine()->create([
    'vmid' => 102,
    'name' => 'ubuntu-102',
    'sockets' => 1,
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
    'password' => $guestPassword, // Optional; prefer SSH keys.
    'ssh_key' => $publicKey, // Contents of an OpenSSH .pub file, not its path.
], importTimeout: 300);
```

`image` takes a volume ID such as `local:import/ubuntu.qcow2`, not a bare filename, absolute path, or download URL. Proxmox copies the source into a new per-VM disk using `import-from`; the reusable source is not attached as a shared writable disk. The package defaults to `virtio-scsi-pci`, booting from `scsi0`, and a serial console (`serial0=socket`, `vga=serial0`). Explicit native controller, boot, and console choices are preserved.

Without `disk`, creation returns the import task UPID immediately and retains the image's virtual disk size. With `image` **and** `disk`, the method waits for successful import (up to `importTimeout` seconds, 300 by default), then submits a separate absolute-size resize and returns the **resize task UPID**. Monitor that final task before starting the VM. Proxmox cannot shrink disks: request at least the source image's virtual size, which can differ from its compressed file size. Guest partition/filesystem expansion depends on the image's Cloud-Init configuration.

Run image provisioning with a requested disk size in a queued job rather than a short web request. `PROXMOX_TIMEOUT` still controls each HTTP request; `importTimeout` controls the import polling budget. An import failure or timeout throws without submitting a resize. A timeout does not cancel the remote task, and a VM may already exist after a later error; inspect Proxmox before retrying. No automatic deletion or retry is performed. `start` cannot be enabled with `image` and `disk`, because the VM must not boot before resizing finishes.

Grant the API token access to the source storage, VM allocation, destination storage allocation, network bridge, and `VM.Config.Disk` for resizing. Passwords and keys are guest credentials, not API-token credentials; use only public keys and keep passwords out of committed code. Password-based SSH access also depends on the guest image's SSH policy.

See the [Cloud-Init guide](https://pve.proxmox.com/wiki/Cloud-Init_Support), [storage content types](https://pve.proxmox.com/pve-docs/chapter-pvesm.html#_common_storage_properties), and [API Viewer](https://pve.proxmox.com/pve-docs/api-viewer/index.html) for the create, storage-content, task-status, and resize endpoints.

### Raw HTTP Requests

The generic `get()`, `post()`, `put()`, `delete()`, and `request()` methods still return Laravel HTTP responses for custom endpoints or when you need headers and status codes. For these raw methods, call `throw()` explicitly to raise HTTP errors. GET parameters are sent as query parameters, and write parameters are sent as form data:

```php
$response = Proxmox::get('nodes');

Proxmox::post('nodes/pve1/qemu', [
    'vmid' => 101,
    'name' => 'web',
])->throw();
```

TLS verification is enabled by default and can be configured with `PROXMOX_VERIFY_SSL`; request timeout defaults to 30 seconds and can be changed with `PROXMOX_TIMEOUT`.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Proxmox! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Nima Asaadi](https://github.com/aboutnima)
- [All Contributors](../../contributors)

## License

Proxmox is open-sourced software licensed under the [MIT license](LICENSE.md).
