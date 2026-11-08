---
name: proxmox-development
description: >
  Integrate a Laravel application with the Proxmox REST API using this package.
license: MIT
metadata:
  author: Nima Asaadi
---

# Proxmox

Use this skill when a Laravel application integrates with Proxmox through `aboutnima/proxmox`.

## Primary Goal

Store named cluster connections and use the live API. The package's only Eloquent model is `Proxmox\Models\Cluster`, backed by `proxmox_clusters`. Let the consuming application own node/VM persistence, customer ownership, caching, and service lifecycles.

## Workflow

### 1. Install and store connections

- Requires PHP `^8.3`, Laravel `^12.0` or `^13.0` with a compatible PHP version, a database, and an application encryption key.
- Run `composer require aboutnima/proxmox`, `php artisan proxmox:install --migrate`, then `php artisan proxmox:create-cluster hetzner`.
- The installer publishes configuration and one migration for `proxmox_clusters`. `--migrate` runs all pending Laravel migrations. Rerun the installer to skip already published files.
- The connection command prompts for an API URL including `/api2/json`, a token ID in `user@realm!token` form, and a hidden token secret. Optional flags: `--url`, `--token-id`, `--timeout`, `--connect-timeout`, `--ca-bundle`, and `--insecure`. There is no secret command-line option.
- `Proxmox\Facades\Cluster` resolves API clients; `Proxmox\Models\Cluster` stores connection settings. Alias the model as `ClusterRecord` when importing both. API token secrets are encrypted with the application's key and hidden from model arrays and JSON. Validate application input before saving through Eloquent; the setup command validates its own inputs.
- Keep TLS verification enabled. `ca_bundle` accepts a certificate file path or PEM contents; the command stores a supplied file's contents. Inline PEM certificates require cURL support for `CURLOPT_CAINFO_BLOB`.
- The default stored connection name comes from `PROXMOX_CONNECTION` / `proxmox.default`, initially `default`. There are no package modes or configured connection lists.
- Every `Cluster::connection($name)` call reads stored settings and builds a fresh client. Bulk updates, renames, and deletions are reflected on subsequent lookups without cache invalidation or model listeners. Existing client variables retain their original settings. Container injection of `Proxmox\Proxmox` also resolves a fresh default client.
- `Cluster::connectUsing($name, $settings)` returns a standalone client without saving a database record or registering its name. Use the returned client directly; `connection($name)` still resolves stored clusters. HTTP settings include `timeout`, `connect_timeout`, `verify_ssl`, `ca_bundle`, and `retry` (`times`, `sleep`). GET retries apply only to connection failures; writes are not retried automatically.
- Query `ClusterRecord` to list stored connections. The package maintains no connection registry in memory.

### 2. Fetch live data

```php
use Proxmox\Facades\Cluster;

$connection = Cluster::connection('hetzner');
$nodes = $connection->nodes();
$node = $connection->node('pve1');
$vms = $node->virtualMachines();
$vm = $node->virtualMachine(101);
$status = $vm->status();
$config = $vm->config();
```

- `nodes()` and `virtualMachines()` fetch plain arrays immediately, with the `data` envelope removed. They never save local node or VM records. Empty listings return `[]`. Wrap arrays in `collect()` for local filtering or aggregation.
- `node($name)` and `virtualMachine($id)` select contexts without HTTP calls. A node is identified by its Proxmox name, not a local database ID. VMIDs are integers or integer strings from 100 to 999999999.
- `Node` and `VirtualMachine` are live API resources, separate from Eloquent. VM snapshots start empty. `status()` fetches without updating the snapshot; `refresh()` updates it in memory. `toArray()` and JSON expose the snapshot without further requests or database writes. Associative response keys are sorted recursively; list order and field values are preserved.
- Node methods include `status()`, `resources()`, `storages('images')`, and `images($storage)`.
- VM methods include `osInfo()`, `networkInfo()`, `username()`, `startup()`, `usage()`, `allocatedResources()`, and `availableResources()`. `cpuHistory()`, `memoryHistory()`, `networkHistory()`, and `diskHistory()` fetch individual history series; `usageHistory()` fetches all four in one request. Guest information requires the QEMU agent; unavailable agent data can be null. Permission errors still throw.
- Memory uses MiB and disks use GiB in categorized summaries and management methods. VM available resources concern its own allocation; node/storage capacity is administrator data.
- Applications choose their own models, migrations, caching, and authorization. Retain a connection name, VMID, and current node for routing; update node placement when VMs move and use an application record ID for each service lifecycle. Cache returned arrays rather than connection or resource objects.

### 3. Create and manage VMs

```php
$vmid = $connection->nextVirtualMachineId();
$task = $node->createVirtualMachine([
    'vmid' => $vmid,
    'name' => 'web',
    'cores' => 2,
    'memory' => 4096,
    'storage' => 'local-lvm',
    'disk' => 32,
]);
$task->wait();

$vm = $node->virtualMachine($vmid);
$vm->start()->wait();
$vm->setCpu(cores: 4)->wait();
$vm->setMemory(8192)->wait();
$vm->growDisk('scsi0', 10)->wait();
```

- Creation accepts native QEMU fields or readable fields documented in the README. Do not mix readable fields with their native equivalents. The example creates an empty disk; supply an image or native installation parameters when an operating system is needed. Handle concurrent VMID allocation collisions in the application.
- For QCOW2 imports, the image must already be on Proxmox import storage. With `image` and `disk`, creation waits for import and returns the resize task; use a queued job and wait before starting. Failed imports and timeouts do not automatically delete remote VMs.
- Power methods: `start`, `shutdown`, `restart`, `pause`, `hibernate`, `stop`, `reset`, and `resume`. Mutations return `Task` objects. `stop` and `reset` are immediate operations; `shutdown` and `restart` request graceful guest shutdown.
- Memory is MiB, CPU cores are per socket, and `growDisk` adds GiB to an existing disk. Pending changes may require a restart. Mutations do not update snapshots; call `refresh()`.
- `delete($purge = true)` destroys the remote VM and its unreferenced disks. Update application records separately after remote success.
- Track tasks with `status()`, `isRunning()`, `wait()`, and `log()`, or reconnect using `$connection->task($upid)`. Keep the connection name alongside the UPID. `wait()` accepts `OK` and `WARNINGS: n` as success. Timeouts stop local polling without cancelling remote work.

### 4. Handle errors and test

- Resource methods throw Laravel HTTP exceptions on failures and `UnexpectedResponseException` on malformed data. Configuration errors throw `InvalidConfigurationException`. Package exceptions implement `Proxmox\Exceptions\ProxmoxException`.
- Raw `get`, `post`, `put`, `delete`, and `request` return Laravel HTTP responses. Call `throw()` and `json('data')` explicitly. Endpoints are relative paths; GET/DELETE parameters use query strings and POST/PUT use form data.
- Use `Http::preventStrayRequests()` and `Http::fake()` for application tests. Use `connectUsing` for API-only tests or create a cluster record in the test database to exercise stored resolution. Node/VM database records are never required by resource operations. Fake task polling when waiting for mutations.

## References

- `README.md` for install, connection settings, creation parameters, management, testing, and upgrade guidance.
- `config/proxmox.php` for the default stored connection name.
- `src/Models/Cluster.php` for stored connection settings.
- `src/Facades/Cluster.php` for the public facade.
- `src/Resources/Nodes/Node.php` for live node selection, listings, and VM creation.
- `src/Resources/VirtualMachines/VirtualMachine.php` for live VM data and management.
- `src/Resources/Tasks/Task.php` for background tasks.

## Examples

```php
use Illuminate\Support\Facades\Http;
use Proxmox\Facades\Cluster;

$connection = Cluster::connectUsing('testing', [
    'url' => 'https://proxmox.example.test:8006/api2/json',
    'api_token_id' => 'laravel@pve!test',
    'api_token_secret' => 'test-secret',
]);

Http::preventStrayRequests();
Http::fake(['*/nodes/pve1/qemu' => Http::response(['data' => [['vmid' => 101]]])]);

expect($connection->node('pve1')->virtualMachines())->toBe([['vmid' => 101]]);
```

## Anti-patterns

- Do not add node/VM database relationships, ownership traits, or synchronization to the package's cluster model. Define them in the consuming application when needed.
- Do not call Eloquent persistence methods on node or VM resources. Their `refresh()` and serialization methods concern an in-memory snapshot; VM `delete()` destroys the remote VM.
- Do not chain `all()` or `create()` onto `virtualMachines()`; it is an array. Use `createVirtualMachine()` on the node.
- Do not call API operations from application model lifecycle hooks implicitly. Handle provisioning and persistence explicitly.
- Do not log decrypted token secrets or `toConnectionConfig()` results. Retain keys needed to decrypt stored secrets.
- Do not use a VMID alone as a customer authorization check or a permanent service identity.
- Do not retry VM creation blindly after failure or timeout. Remote operations and database writes are independent.
