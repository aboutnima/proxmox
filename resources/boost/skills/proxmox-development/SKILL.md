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

- Use node-scoped VM operations, array listings, and fetched VM objects for Proxmox nodes, QEMU VMs, and tasks, with authentication, HTTP errors, and response unwrapping handled by the package.

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
- `nodes()` fetches and returns an array of nodes. `node($name)` selects a node context without making a request; `nodes($name)` is not supported.
- `node($name)->virtualMachines()` returns VM operations without fetching. Call `all()` to fetch an array of VMs, or `create($configuration)` to create a VM. `node($name)->images($storage)` fetches an array immediately. Empty listings return `[]`.
- Wrap fetched arrays in `collect($data)` to filter, sort, or aggregate locally. Collection operations do not send additional API requests.
- `node($name)->status()` fetches node status as an array.
- `node($name)->virtualMachine($id)` immediately fetches runtime status and returns a `Proxmox\Resources\VirtualMachines\VirtualMachine` object. An ID is required; use `virtualMachines()->all()` for a listing.
- VM `toArray()` and JSON serialization expose the fetched snapshot without extra requests. `status()` fetches fresh status without changing the snapshot; `refresh()` updates the snapshot and returns the same VM object. `config()` fetches configuration separately.
- Node names and VM IDs are validated before fetching. IDs must be integers or integer strings between 100 and 999999999. HTTP 404 responses throw when selecting a VM.

### 3. Create VMs and track tasks

- Create with `node($name)->virtualMachines()->create($configuration, importTimeout: 300)`. Pass an array; its PHPDoc declares common keys and value types for IDE completion. Additional native QEMU fields are passed through to Proxmox.
- Supply `vmid` (required), `name`, `sockets`, `cores` (per socket), `memory` (MiB), `storage`, and `disk` (GiB) as needed. Get a free ID with `Proxmox::nextVirtualMachineId()`.
- Use `bridge` and either `ip: 'dhcp'` or a static `ip` with integer `prefix` and optional `gateway`. An IP needs `storage` plus `bridge` (or native `net0`) and adds a Cloud-Init drive.
- Use `user`, `password`, and `ssh_key` for guest Cloud-Init credentials. `ssh_key` takes unencoded OpenSSH public key text, with newlines between multiple keys; the package encodes it.
- `create()` returns a `Proxmox\Resources\Tasks\Task`. Access `$task->upid` / `$task->node` and call `$task->wait(timeout: 300)` to wait. Failures throw `TaskFailedException` or `TaskTimeoutException`. `status()`, `isRunning()`, and `log()` are also available; `log()` returns an array.
- Rebuild a stored task with `Proxmox::task($upid)`. Resize an existing VM with `virtualMachine($id)->resize('scsi0', '+10G')`, which also returns a `Task`.
- For cloud images, fetch `node($name)->images($storage)` and pass a `volid` such as `local:import/ubuntu.qcow2` as `image`. The file must already exist on file-based Proxmox storage with import content enabled.
- With `image` and `disk`, creation waits for import and returns the resize task. Run it in a queued job and wait before starting the VM. Disks cannot shrink. Failed imports and timeouts throw without deleting remote VMs or cancelling tasks.
- Native QEMU fields, including additional disks and IPv6 in `ipconfig0`, are supported. Do not mix readable fields with native equivalents: `disk`/`image` with `scsi0`, `bridge` with `net0`, `ip` with `ipconfig0`, or guest credentials with their native equivalents. Readable Cloud-Init fields also conflict with `ide2`.

### 4. Handle errors and raw requests

- Resource methods unwrap `data`, throw Laravel `RequestException` on HTTP errors, and throw `Proxmox\Exceptions\UnexpectedResponseException` on malformed data.
- Missing or invalid configuration throws `Proxmox\Exceptions\InvalidConfigurationException` on the first request.
- Every package exception implements `Proxmox\Exceptions\ProxmoxException`.
- Raw `get()`, `post()`, `put()`, `delete()`, and `request()` return HTTP responses. Call `throw()` and `json('data')` yourself.
- GET and DELETE parameters go in the query string; POST and PUT parameters are sent as form data.

## References

- `README.md` for install and usage examples.
- `config/proxmox.php` for connection, timeout, retry, and TLS settings.
- `src/Facades/Proxmox.php` for the facade methods.
- `src/Resources/Nodes/Node.php` for node status, VM operations, and image listings.
- `src/Resources/VirtualMachines/` for fetched VMs, listing and creation operations, creation key types, and VM configuration.
- `src/Resources/Tasks/Task.php` for background tasks.
- `src/Exceptions/` for package exceptions.

## Examples

```php
use Proxmox\Facades\Proxmox;

$onlineNodes = collect(Proxmox::nodes())->where('status', 'online')->pluck('node');

$vms = Proxmox::node('pve1')->virtualMachines()->all();
$runningVms = collect($vms)->where('status', 'running')->values()->all();

$vm = Proxmox::node('pve1')->virtualMachine(101);
$data = $vm->toArray();
$config = $vm->config();

$task = Proxmox::node('pve1')->virtualMachines()->create([
    'vmid' => Proxmox::nextVirtualMachineId(),
    'name' => 'web-server',
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
    'ssh_key' => $publicKey,
], importTimeout: 300);

$task->wait();
```

In application tests, fake the Laravel HTTP client:

```php
use Illuminate\Support\Facades\Http;

Http::fake(['*/nodes/pve1/qemu' => Http::response(['data' => [['vmid' => 101]]])]);

expect(Proxmox::node('pve1')->virtualMachines()->all())->toBe([['vmid' => 101]]);
```

## Anti-patterns

- Do not pass an absolute URL as an endpoint; the client accepts only relative API paths.
- Do not use QEMU VM methods for LXC containers.
- Do not call `Proxmox::virtualMachines()`; VM listings require `node($name)->virtualMachines()->all()`.
- Do not disable TLS verification to work around certificate problems; configure a trusted certificate.
- Do not treat a create or resize result as a string UPID or a running VM. Use the returned `Task`.
- Do not append `throw()` or `json('data')` to resource results; they belong to raw HTTP responses.
- Do not chain query methods onto VM operations or listing arrays; use `collect($data)` for local operations.
- Do not use the removed pending builder or node `makeVirtualMachine()` / `createVirtualMachine()` methods; use `virtualMachines()->create($configuration)`.
- Do not use the removed facade `getNodes()`, `getNode()`, `getVirtualMachines()`, `getVirtualMachine()`, `getImages()`, or `createVirtualMachine()` shortcuts.
- Do not assume a VM snapshot stays current after management operations; call `refresh()` to update it.
- Do not assume VM listing rows contain the same fields as runtime status or configuration.
- Do not send DELETE parameters in a request body; `delete()` already uses the query string.
- Do not enable `start` together with `image` and `disk`; start only after the returned resize task succeeds.
- Do not use an ISO, a Laravel-local file path, or an existing VM disk as the `image` source.
- Do not retry VM creation blindly after a failure or timeout; inspect Proxmox first.
