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

- Use the fluent resource API for Proxmox nodes, QEMU VMs, and tasks, with authentication, HTTP errors, and response unwrapping handled by the package.

## Workflow

### 1. Install and configure

- Install with `composer require aboutnima/proxmox`.
- Optionally publish configuration with `php artisan vendor:publish --tag=proxmox-config`.
- Set `PROXMOX_URL` to the Proxmox API base URL, including `/api2/json`.
- Set `PROXMOX_API_TOKEN_ID` and `PROXMOX_API_TOKEN_SECRET` for API-token authentication.
- Optional settings: `PROXMOX_TIMEOUT` (30), `PROXMOX_CONNECT_TIMEOUT` (10), `PROXMOX_VERIFY_SSL` (true), `PROXMOX_RETRY_TIMES` (0), and `PROXMOX_RETRY_SLEEP` (100 ms). Retries apply only to GET requests that hit connection failures.
- For several Proxmox servers, add entries under `connections` in `config/proxmox.php` and call `Proxmox::connection('name')`. `PROXMOX_CONNECTION` selects the default.

### 2. Read nodes and VMs

- Import `Proxmox\Facades\Proxmox`.
- List nodes with `nodes()->all()` and list QEMU VMs on a selected node with `node($name)->virtualMachines()->all()`. `get()` is an alias for `all()`.
- Chain `where('key', $value)`, `where('key', '>=', $value)`, `whereIn()`, `whereNotIn()`, and `when()` before `all()` or `first()`. Queries are immutable and lazy. Each terminal fetches the whole listing, then filters it locally, so filters are not API query parameters.
- Read-only collection methods such as `count()`, `pluck()`, `sum()`, `sortBy()`, `keyBy()`, and `firstWhere()` can be called directly on a query. Register shared filters with `Proxmox\Support\ResourceQuery::macro()`.
- Listings return `Illuminate\Support\Collection` instances of attribute arrays. `first()` returns an array or `null`.
- For one resource, use `node($name)->status()`, `node($name)->virtualMachine($id)->status()`, and `virtualMachine($id)->config()`. These return arrays, throw on HTTP 404, and have no `all()` or `where()`.
- VM IDs must be integers (or integer strings) between 100 and 999999999. Node names and VM IDs are validated when selected.

### 3. Create VMs and track tasks

- Get a free ID with `Proxmox::nextVirtualMachineId()`.
- Build the VM with `node($name)->virtualMachines()->make($vmid)`, chain `name()`, `sockets()`, `cores()` (per socket), `memory()` (MiB), `storage()`, `disk()` (GiB), and `bridge()`, then call `create()`. `create($array)` accepts the same readable fields (`vmid`, `disk`, `storage`, `bridge`, `ip`, `prefix`, `gateway`, `user`, `password`, `ssh_key`, `image`).
- For networking, use `ip($address, prefix: 24, gateway: ...)` or `dhcp()`. An IP needs `storage` plus `bridge` (or native `net0`) and adds a Cloud-Init drive.
- Set guest Cloud-Init credentials with `user()`, `password()`, and `sshKey(...$publicKeys)`. Pass only unencoded OpenSSH public key text; the package encodes it.
- `with([...])` merges native QEMU parameters. `when()` and `unless()` work on the builder.
- `create()` returns a `Proxmox\Tasks\Task` (`$task->upid`, `$task->node`), not a running VM. Call `$task->wait(timeout: 300)` to block until it finishes; it throws `TaskFailedException` or `TaskTimeoutException`. `status()`, `isRunning()`, and `log()` are also available.
- Rebuild a stored task with `Proxmox::task($upid)`.
- Resize disks with `virtualMachine($id)->resize('scsi0', '+10G')`, which also returns a `Task`.
- To use a cloud image, list import sources with `node($name)->images($storage)->all()` and pass a `volid` such as `local:import/ubuntu.qcow2` to `image()`. The file must already exist on file-based Proxmox storage with `import` content enabled, not on the Laravel server.
- With both `image` and `disk`, `create(importTimeout: 300)` waits for the import, then returns the resize task. Run this in a queued job and wait for the returned task before starting the VM. Disks cannot shrink. Failed imports or timeouts throw, and remote VMs and tasks are not cancelled or deleted.
- Native API fields remain supported, including IPv6 in `ipconfig0`. Do not mix readable fields with their native equivalents: `disk`/`image` with `scsi0`, `bridge` with `net0`, `ip` with `ipconfig0`, or credential fields with theirs. Readable Cloud-Init fields also conflict with `ide2`.

### 4. Handle errors and raw requests

- Resource methods unwrap `data`, throw Laravel `RequestException` on HTTP errors, and throw `Proxmox\Exceptions\UnexpectedResponseException` on malformed data.
- Missing or invalid configuration throws `Proxmox\Exceptions\InvalidConfigurationException` on the first request.
- Every package exception implements `Proxmox\Exceptions\ProxmoxException`.
- Raw `get()`, `post()`, `put()`, `delete()`, and `request()` return HTTP responses. Call `throw()` and `json('data')` yourself.
- GET and DELETE parameters go in the query string; POST and PUT parameters are sent as form data.
- `getNodes()`, `getNode()`, `getVirtualMachines()`, `getVirtualMachine()`, `getImages()`, and `createVirtualMachine()` are direct shortcuts with the same return values as the fluent API.

## References

- `README.md` for install and usage examples.
- `config/proxmox.php` for connection, timeout, retry, and TLS settings.
- `src/Facades/Proxmox.php` for the facade methods.
- `src/Nodes/` for nodes and node listings.
- `src/VirtualMachines/` for VMs, VM listings, the pending builder, and VM configuration.
- `src/Images/` for importable image listings.
- `src/Tasks/` for background tasks.
- `src/Support/ResourceQuery.php` for shared listing filters and macros.
- `src/Exceptions/` for package exceptions.

## Examples

```php
use Proxmox\Facades\Proxmox;

$onlineNodes = Proxmox::nodes()->where('status', 'online')->pluck('node');

$runningVms = Proxmox::node('pve1')->virtualMachines()->where('status', 'running')->all();

$config = Proxmox::node('pve1')->virtualMachine(101)->config();

$task = Proxmox::node('pve1')->virtualMachines()
    ->make(Proxmox::nextVirtualMachineId())
    ->name('web-1')
    ->cores(2)
    ->memory(4096)
    ->image('local:import/ubuntu-26.04-server-cloudimg-amd64.qcow2')
    ->storage('local-lvm')
    ->disk(32)
    ->bridge('vmbr0')
    ->ip('192.0.2.10', prefix: 24, gateway: '192.0.2.1')
    ->user('ubuntu')
    ->sshKey($publicKey)
    ->create(importTimeout: 300);

$task->wait();
```

In application tests, fake the Laravel HTTP client:

```php
use Illuminate\Support\Facades\Http;

Http::fake(['*/nodes/pve1/qemu' => Http::response(['data' => [['vmid' => 101]]])]);

expect(Proxmox::node('pve1')->virtualMachines()->pluck('vmid')->all())->toBe([101]);
```

## Anti-patterns

- Do not pass an absolute URL as an endpoint; the client accepts only relative API paths.
- Do not use QEMU VM methods for LXC containers.
- Do not call `Proxmox::virtualMachines()`; VM listings require `node($name)->virtualMachines()`.
- Do not disable TLS verification to work around certificate problems; configure a trusted certificate.
- Do not treat a create or resize result as a string UPID or a running VM. Use the returned `Task`.
- Do not append `throw()` or `json('data')` to resource results; they belong to raw HTTP responses.
- Do not call list methods such as `all()` or `where()` after selecting a node or VM by identifier.
- Do not assume `where()` reduces the data fetched from Proxmox, or that status arrays match listing rows.
- Do not send DELETE parameters in a request body; `delete()` already uses the query string.
- Do not enable `start` together with `image` and `disk`; start only after the returned resize task succeeds.
- Do not use an ISO, a Laravel-local file path, or an existing VM disk as the `image` source.
- Do not retry VM creation blindly after a failure or timeout; inspect Proxmox first.
