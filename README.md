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

`vmid` must be unique. `cores` sets CPU cores per socket, `memory` is RAM in MiB, and `disk` is a positive integer size in GiB. Replace `storage`, `bridge`, and the example network addresses with your host's actual values. The package does not guess the subnet prefix or gateway.

| Readable field | Proxmox configuration |
|---|---|
| `disk` + `storage` | Allocates `scsi0` on that storage, using `virtio-scsi-pci` unless you supply `scsihw`. |
| `bridge` | Creates `net0`, a VirtIO network card connected to the named bridge. |
| `ip` + `prefix` + optional `gateway` | Builds the IPv4 Cloud-Init configuration in `ipconfig0`. |
| `ip` + `storage` | Automatically attaches the Cloud-Init drive as `ide2` on that storage. |

For static IPv4, supply a plain address (without `/24`), an integer `prefix` between 0 and 32, and optionally a `gateway`. For DHCP, use `'ip' => 'dhcp'` and omit `prefix` and `gateway`. An `ip` requires `storage` and either `bridge` or an explicit native `net0` card. Without `ip`, no Cloud-Init drive is automatically added.

Native QEMU parameters remain supported, including additional disks, VLAN options, and IPv6 through `ipconfig0`. Do not combine `disk` with `scsi0`, `bridge` with `net0`, or `ip` with `ipconfig0`/`ide2`: conflicting settings and invalid readable fields throw `InvalidArgumentException` before any request is sent. For a custom Cloud-Init drive or network configuration, use the native fields together. The same readable fields work with `Proxmox::createVirtualMachine('my', $configuration)`.

The method returns the asynchronous task UPID, not a completed VM. IP configuration still requires a guest image with Cloud-Init installed; attaching the drive alone does not configure an arbitrary OS or allocate a public IP. Creating the hardware does not install an operating system or clone a template. See the [Cloud-Init guide](https://pve.proxmox.com/wiki/Cloud-Init_Support) and the [Proxmox VE API Viewer](https://pve.proxmox.com/pve-docs/api-viewer/index.html), `POST /nodes/{node}/qemu`.

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
