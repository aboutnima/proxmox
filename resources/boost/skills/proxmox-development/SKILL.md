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

- Install with `composer require aboutnima/proxmox`, then run `php artisan proxmox:install` and choose a mode (or pass `--mode=api|inventory`).
- `api` mode (default) reads connections from `.env` and `config/proxmox.php` and needs no database.
- In api mode, set `PROXMOX_URL` to the Proxmox API base URL, including `/api2/json`.
- Set `PROXMOX_API_TOKEN_ID` and `PROXMOX_API_TOKEN_SECRET` for API-token authentication.
- Optional settings: `PROXMOX_TIMEOUT` (30), `PROXMOX_CONNECT_TIMEOUT` (10), `PROXMOX_VERIFY_SSL` (true), `PROXMOX_CA_BUNDLE` (CA file path or PEM certificate), `PROXMOX_RETRY_TIMES` (0), and `PROXMOX_RETRY_SLEEP` (100 ms). Retries apply only to GET requests that hit connection failures.
- For several Proxmox servers, add entries under `connections` in `config/proxmox.php` and call `Proxmox::connection('name')`. `PROXMOX_CONNECTION` selects the default. Use `Proxmox::connectUsing($name, $config)` for runtime connections.
- `inventory` mode (`php artisan proxmox:install --mode=inventory --migrate`) publishes the `create_proxmox_tables` migration for `proxmox_clusters`, `proxmox_nodes`, and `proxmox_virtual_machines`, and reads clusters only from the database. Add clusters with `php artisan proxmox:create-cluster {name}`, or move a `.env` connection with `php artisan proxmox:create-cluster {name} --from-env [--connection=default]`. Keep `proxmox_nodes` current with `php artisan proxmox:sync-nodes [cluster...] [--all]` (schedule `--all`) or `$cluster->syncNodes()`; nodes Proxmox no longer lists are kept with the `missing` status.
- In inventory mode, `Proxmox::connection($name)` resolves the cluster named `$name`. Add `Proxmox\Concerns\HasVirtualMachines` to owner models such as `User`; `Proxmox\Models\VirtualMachine` has a polymorphic `owner`, `cluster`, and `node`. Eloquent operations change inventory records only. Use `Proxmox\Resources\VirtualMachines\VirtualMachine::make($record)` for live VM operations without fetching.

### 2. Read nodes and VMs

- Import `Proxmox\Facades\Proxmox`.
- `nodes()` fetches and returns an array of nodes. `node($name)` selects a node context without making a request; `nodes($name)` is not supported.
- `node($name)->virtualMachines()` returns VM operations without fetching. Call `all()` to fetch an array of VMs, or `create($configuration)` to create a VM. `node($name)->images($storage)` fetches an array immediately. Empty listings return `[]`.
- Wrap fetched arrays in `collect($data)` to filter, sort, or aggregate locally. Collection operations do not send additional API requests.
- Resource results sort associative keys alphabetically at every nesting level, including VM snapshots and JSON. List item order and field values are preserved.
- `node($name)->status()` fetches node status as an array.
- `node($name)->virtualMachine($id)` immediately fetches runtime status and returns a `Proxmox\Resources\VirtualMachines\VirtualMachine` object. An ID is required; use `virtualMachines()->all()` for a listing.
- VM `toArray()` and JSON serialization expose the fetched snapshot without extra requests. `status()` fetches fresh status without changing the snapshot; `refresh()` updates the snapshot and returns the same VM object. `config()` fetches configuration separately.
- Node names and VM IDs are validated before fetching. IDs must be integers or integer strings between 100 and 999999999. HTTP 404 responses throw when selecting a VM.
- For categorized data, use VM `usage()` (live status), `resources()` (applied allocation; pending changes ignored), and `availableResources()` (free resources inside the VM's own allocation). On a node, use `resources()` (capacity, live usage, and allocation across non-template VMs) and `storages('images')` (enabled storages keyed by name). Memory is in MiB and disks are in GiB, rounded down, matching `setMemory()` and `growDisk()`. Each call sends fresh requests.
- `availableResources()` never reads node or storage capacity, so it is safe to show to the VM owner. It returns `cpu` (`vcpus`, `used` and `available` as percentages), `memory` (`total`, `used`, `available` in MiB), `disks` (per disk `total`, `used`, `available` in GiB from this VM's storage volumes; works without the guest agent, and `used` is `null` when the storage does not report it), and `filesystems` from the QEMU guest agent (GiB per mountpoint), or `null` when the VM is stopped or the agent is disabled or not running. Keep node `resources()` and `storages()` for administrators.
- For charts, use `cpuHistory()`, `memoryHistory()` (MiB), `networkHistory()`, and `diskHistory()` (bytes/s). Each takes `$timeframe` (`hour`, `day`, `week`, `month`, `year`) and `$consolidation` (`average`, `max`) and returns a list of points with a Unix `time`; values are `null` where Proxmox has no data.

### 3. Create VMs and track tasks

- Create with `node($name)->virtualMachines()->create($configuration, importTimeout: 300)`. Pass an array; its PHPDoc declares common keys and value types for IDE completion. Additional native QEMU fields are passed through to Proxmox.
- Supply `vmid` (required), `name`, `sockets`, `cores` (per socket), `memory` (MiB), `storage`, and `disk` (GiB) as needed. Get a free ID with `Proxmox::nextVirtualMachineId()`.
- Use `bridge` and either `ip: 'dhcp'` or a static `ip` with integer `prefix` and optional `gateway`. An IP needs `storage` plus `bridge` (or native `net0`) and adds a Cloud-Init drive.
- Use `user`, `password`, and `ssh_key` for guest Cloud-Init credentials. `ssh_key` takes unencoded OpenSSH public key text, with newlines between multiple keys; the package encodes it.
- `create()` returns a `Proxmox\Resources\Tasks\Task`. Access `$task->upid` / `$task->node` and call `$task->wait(timeout: 300)` to wait. Failures throw `TaskFailedException` or `TaskTimeoutException`. `status()`, `isRunning()`, and `log()` are also available; `log()` returns an array.
- Rebuild a stored task with `Proxmox::task($upid)`.
- For cloud images, fetch `node($name)->images($storage)` and pass a `volid` such as `local:import/ubuntu.qcow2` as `image`. The file must already exist on file-based Proxmox storage with import content enabled.
- With `image` and `disk`, creation waits for import and returns the resize task. Run it in a queued job and wait before starting the VM. Disks cannot shrink. Failed imports and timeouts throw without deleting remote VMs or cancelling tasks.
- Native QEMU fields, including additional disks and IPv6 in `ipconfig0`, are supported. Do not mix readable fields with native equivalents: `disk`/`image` with `scsi0`, `bridge` with `net0`, `ip` with `ipconfig0`, or guest credentials with their native equivalents. Readable Cloud-Init fields also conflict with `ide2`.

### 4. Change existing VMs

- Start and control VM power with `start()`, `shutdown()`, `restart()`, `pause()`, `hibernate()`, `resume()`, `stop()`, and `reset()`. Each returns a `Task`.
- `shutdown()` and `restart()` request a graceful guest shutdown; either can take an optional timeout in seconds. `stop()` removes power immediately and `reset()` is an immediate hardware reset, so both can cause data loss.
- `pause()` keeps state in memory; `hibernate()` saves state to disk and requires VM state storage. `resume()` resumes a paused or hibernated VM.
- Set CPU with `virtualMachine($id)->setCpu(cores: 4, sockets: 1)`. Cores are per socket; omit `sockets` to keep the current count. `setCores($cores)` is shorthand for `setCpu(cores: $cores)`.
- Set memory in MiB with `virtualMachine($id)->setMemory(8192)`.
- Grow an existing disk by GiB with `virtualMachine($id)->growDisk('scsi0', 10)`. It does not attach a new disk, and disks cannot shrink.
- Each method validates positive integers and returns a `Task`.
- Unless CPU or memory hotplug is enabled, Proxmox can keep CPU and memory changes for a running VM pending until the VM restarts. Wait for the returned task, then call `refresh()` to update the snapshot.

### 5. Handle errors and raw requests

- Resource methods unwrap `data`, throw Laravel `RequestException` on HTTP errors, and throw `Proxmox\Exceptions\UnexpectedResponseException` on malformed data.
- Missing or invalid configuration throws `Proxmox\Exceptions\InvalidConfigurationException` on the first request.
- Every package exception implements `Proxmox\Exceptions\ProxmoxException`.
- Raw `get()`, `post()`, `put()`, `delete()`, and `request()` return HTTP responses. Call `throw()` and `json('data')` yourself.
- GET and DELETE parameters go in the query string; POST and PUT parameters are sent as form data.

## References

- `README.md` for install and usage examples.
- `config/proxmox.php` for the mode, connection, timeout, retry, TLS settings, and inventory model classes.
- `src/Models/` for the inventory `Cluster`, `Node`, and `VirtualMachine` models, and `src/Concerns/HasVirtualMachines.php` for owners.
- `src/Facades/Proxmox.php` for the facade methods.
- `src/Resources/Nodes/Node.php` for node status, resources, storages, VM operations, and image listings.
- `src/Resources/VirtualMachines/` for fetched VMs, CPU/memory updates, disk resizing, listing and creation operations, creation key types, and VM configuration.
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

$vm->start()->wait();
$vm->shutdown(timeout: 60)->wait();
$vm->restart()->wait();
$vm->pause()->wait();
$vm->hibernate()->wait();
$vm->resume()->wait();
$vm->stop()->wait();
$vm->reset()->wait();

$vm->setCpu(cores: 4)->wait();
$vm->setMemory(8192)->wait();
$vm->growDisk('scsi0', 10)->wait();
$vm->refresh();

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

In inventory mode, provision through the node's cluster client, save the inventory separately, and wrap the record to manage the live VM:

```php
use Proxmox\Models\Cluster;
use Proxmox\Resources\VirtualMachines\VirtualMachine;

$cluster = Cluster::query()->where('name', 'eu-1')->firstOrFail();
$node = $cluster->nodes()->firstOrCreate(['name' => 'pve1']);

$client = $node->cluster->client();
$vmid = $client->nextVirtualMachineId();
$client->node($node->name)->virtualMachines()->create([
    'vmid' => $vmid,
    'name' => 'web',
    'cores' => 2,
    'memory' => 2048,
])->wait();

$record = $user->virtualMachines()->create([
    'cluster_id' => $node->cluster_id,
    'node_id' => $node->id,
    'vmid' => $vmid,
    'name' => 'web',
]);

$vm = VirtualMachine::make($record); // No request; requires a saved, non-deleted record.
$vm->start()->wait();
$vm->setMemory(4096);
$vm->stop()->wait();
$vm->delete()->wait(); // Remote destruction only.
$record->delete();    // Database only.
```

Keep API configuration out of Eloquent attributes. Use the node's cluster client rather than the default facade connection for provisioning. Record existing VMs with normal Eloquent `create()` and explicit `cluster_id`, `node_id`, and `vmid`. Model lifecycle hooks, management methods, `resource()`, `api()`, `creationTask()`, and `withoutProxmox()` are removed. Remote operations never update inventory; handle collisions, task failures, and database failures explicitly.

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
- Do not disable TLS verification to work around certificate problems; configure a trusted certificate or `ca_bundle`.
- Do not expect `config/proxmox.php` connections to work in inventory mode; create database clusters instead.
- Do not pass API token secrets as command-line options or log `Cluster` secrets; `api_token_secret` is encrypted with `APP_KEY` and hidden.
- Do not identify an inventory VM by VMID alone; Proxmox reuses VMIDs, so use the `proxmox_virtual_machines` row ID.
- Do not run `vendor:publish --tag=proxmox-migrations` repeatedly; rerun `php artisan proxmox:install`, which skips migrations that are already published.
- Do not treat a create, `setCpu()`, `setMemory()`, or `growDisk()` result as a string UPID or a running VM. Use the returned `Task`.
- Do not use `growDisk()` to attach a new disk or to set an absolute size; it adds GiB to an existing disk.
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
