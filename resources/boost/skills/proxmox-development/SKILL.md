---
name: proxmox-development
description: >
  Integrate a Laravel application with the Proxmox REST API using this package.
license: MIT
metadata:
  author: Nima Asaadi
---

# Proxmox

Use this skill when a Laravel application needs to integrate with the Proxmox REST API through `aboutnima/proxmox`.

## Primary Goal

- Use the fluent resource API for Proxmox nodes and QEMU VMs, with authentication, HTTP errors, and response unwrapping handled by the package.

## Workflow

### 1. Install and configure

- Install with `composer require aboutnima/proxmox`.
- Optionally publish configuration with `php artisan vendor:publish --tag=proxmox-config`.
- Set `PROXMOX_URL` to the Proxmox API base URL, including `/api2/json`.
- Set `PROXMOX_API_TOKEN_ID` and `PROXMOX_API_TOKEN_SECRET` for API-token authentication.

### 2. Call API endpoints

- Import `Proxmox\Facades\Proxmox`. The package uses the single `Proxmox` root namespace; update older imports when upgrading.
- Use `nodes()->all()` to list cluster nodes and `node($name)->virtualMachine()->all()` to list QEMU VMs on one node. Existing list `get()` calls remain supported.
- Chain `where('key', $value)` or `where('key', '>=', $value)` before `all()` or `first()`. Queries are immutable and lazy; each terminal fetches the whole listing, then filters it locally using Laravel collection comparisons. Filters are not API query parameters.
- List `all()` and `get()` return `Illuminate\Support\Collection` instances containing attribute arrays. List `first()` returns the first matching array or `null`.
- Prefer `node($name)->get()` and `node($name)->virtualMachine($id)->get()` for individual status arrays. These call the node `/status` and VM `/status/current` endpoints, not a filtered list. VM IDs accept positive integers or their string representations.
- `nodes($name)` and `node($name)->virtualMachine($id)` select individual resources. Single-resource objects have `get()` and its `first()` alias, but no `all()` or `where()`.
- All resource methods unwrap `data` and throw HTTP errors automatically, including HTTP 404 on identified resources; only list `first()` can return `null` for no match. Malformed data fails rather than becoming an empty result.
- Create a QEMU VM with `node($name)->virtualMachine()->create($configuration)` or `createVirtualMachine($node, $configuration)`. Supply `vmid`, `cores`, and `memory` (MiB); use readable `disk` (positive integer GiB), `storage`, and `bridge` fields. The method returns the asynchronous task UPID.
- Readable `ip` accepts a plain IPv4 address with an explicit integer `prefix` (0-32) and optional `gateway`. Use `ip => 'dhcp'` without a prefix/gateway for DHCP. IP configuration requires `storage` and `bridge` (or native `net0`) and automatically adds a Cloud-Init drive.
- Native API fields remain supported, including IPv6 in `ipconfig0`. Do not mix `disk`/`scsi0`, `bridge`/`net0`, or `ip` with `ipconfig0`/`ide2`; conflicts throw before making requests.
- IP configuration requires a Cloud-Init-enabled guest image and does not allocate public IPs. VM creation allocates hardware but does not install an OS or clone a template.
- The direct shortcuts `getNodes()` and `getVirtualMachines($node)` return the same collections. Remove `->throw()->json('data')` when migrating existing shortcut calls.
- The direct shortcuts `getNode($name)` and `getVirtualMachine($node, $id)` return individual status arrays.
- Generic `get()`, `post()`, `put()`, `delete()`, and `request()` still return raw HTTP responses. Only these low-level calls require explicit `throw()` and `json('data')`.

## References

- `README.md` for install and usage examples.
- `config/proxmox.php` for connection, timeout, and TLS verification settings.
- `src/Proxmox.php` and `src/Facades/Proxmox.php` for the public client API.
- `src/Resources/` for the fluent node and VM resources.

## Examples

```php
use Proxmox\Facades\Proxmox;

$nodes = Proxmox::nodes()->all();

$onlineNode = Proxmox::nodes()->where('status', 'online')->first();

$runningVms = Proxmox::node('my')->virtualMachine()->where('status', 'running')->all();

$vm = Proxmox::node('my')->virtualMachine(101)->get();

$task = Proxmox::node('my')->virtualMachine()->create([
    'vmid' => 102,
    'name' => 'web-102',
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

In application tests, fake the Laravel HTTP client:

```php
use Illuminate\Support\Facades\Http;

Http::fake(['*/nodes/my/qemu' => Http::response(['data' => [['vmid' => 101]]])]);

expect(Proxmox::node('my')->virtualMachine()->all()->pluck('vmid')->all())->toBe([101]);
```

## Anti-patterns

- Do not pass an absolute URL as an endpoint; the client only accepts relative API paths.
- Do not use the QEMU VM listing method for LXC containers; container helpers are a separate API surface.
- Do not disable TLS verification to work around certificate problems; configure a trusted Proxmox certificate instead.
- Do not append `throw()` or `json('data')` to resource collections; those methods belong to raw HTTP responses.
- Do not call list methods such as `all()` or `where()` after selecting a node or VM by identifier.
- Do not assume `where()` reduces the amount of data fetched from Proxmox, or that single-resource status arrays have the same fields as listing rows.
- Do not assume a bridge or Cloud-Init drive alone assigns an IP address; use a Cloud-Init-enabled guest and provide `ip`/`prefix`/`gateway` or native `ipconfig0`.
- Do not assume raw HTTP methods throw automatically; call `throw()` or inspect their response status.
