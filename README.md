# Proxmox

[![Latest version](https://img.shields.io/packagist/v/aboutnima/proxmox.svg?style=flat-square)](https://packagist.org/packages/aboutnima/proxmox)
[![PHP version](https://img.shields.io/packagist/php-v/aboutnima/proxmox.svg?style=flat-square)](https://packagist.org/packages/aboutnima/proxmox)
[![Tests](https://img.shields.io/github/actions/workflow/status/aboutnima/proxmox/tests.yml?branch=main&label=Tests&style=flat-square)](https://github.com/aboutnima/proxmox/actions/workflows/tests.yml)
[![License](https://img.shields.io/github/license/aboutnima/proxmox?style=flat-square)](LICENSE.md)

A Laravel package for the Proxmox VE REST API. Store named cluster connections with encrypted API credentials, fetch live nodes and QEMU virtual machines, and manage VMs through a fluent API.

The package owns one table: `clusters`. Applications decide how to store, cache, authorize, and display node and VM data. Node and VM API resources hold no database records or ownership relations.

## Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Cluster connections](#cluster-connections)
- [Reading nodes and virtual machines](#reading-nodes-and-virtual-machines)
- [Resources and usage history](#resources-and-usage-history)
- [Managing virtual machines](#managing-virtual-machines)
- [Creating virtual machines](#creating-virtual-machines)
- [Background tasks](#background-tasks)
- [Application storage](#application-storage)
- [Raw HTTP requests](#raw-http-requests)
- [Error handling](#error-handling)
- [Testing](#testing)
- [Upgrading](#upgrading)

## Requirements

| Requirement | Supported configuration |
| --- | --- |
| PHP | `^8.3` |
| Laravel | `^12.0` or `^13.0`, with a compatible PHP version |
| Database | A Laravel-supported database for cluster connections |
| Encryption | An application encryption key for stored API token secrets |
| Proxmox | A reachable Proxmox VE JSON API and an API token permitted to perform your operations |

The resource API manages QEMU VMs. Use the raw HTTP client for other Proxmox endpoints.

## Installation

```bash
composer require aboutnima/proxmox
php artisan proxmox:install --migrate
php artisan proxmox:create-cluster hetzner
```

Laravel discovers the service provider and the `ProxmoxCluster` facade. The installer publishes `config/proxmox.php` and one migration that creates `clusters`. With `--migrate`, it runs Laravel's pending migrations. Without the flag, you can run `php artisan migrate` separately.

The cluster command prompts for the API URL, token identifier, and token secret. Include `/api2/json` in the URL, for example `https://proxmox.example.com:8006/api2/json`. The secret is entered through a hidden prompt.

```php
use Proxmox\Facades\ProxmoxCluster;

$connection = ProxmoxCluster::connection('hetzner');
$nodes = $connection->nodes();
$vms = $connection->node('pve1')->virtualMachines();
```

Node selection uses the Proxmox node name, such as `pve1`. Listings return plain arrays with the API's `data` envelope removed. Empty listings return `[]`.

Rerunning the installer preserves existing configuration and skips migrations that have already been published. You can also publish the files manually:

```bash
php artisan vendor:publish --tag=proxmox-config
php artisan vendor:publish --tag=proxmox-migrations
```

Use the installer for repeatable migration publication.

## Cluster connections

### Store and list connections

Create a stored connection through the command:

```bash
php artisan proxmox:create-cluster hetzner \
    --url=https://proxmox.example.com:8006/api2/json \
    --token-id='laravel@pve!app' \
    --ca-bundle=/absolute/path/to/pve-root-ca.pem
```

The command accepts `--timeout=30`, `--connect-timeout=10`, and `--insecure`. The API token secret is prompted for interactively; it has no command-line option. The command validates unique cluster names containing 1–100 letters, numbers, hyphens, or underscores. A CA file supplied to the command is stored as PEM contents. Validate application-managed input before saving connection records through Eloquent.

For application-managed setup, use the configured cluster model. The default is `Proxmox\Models\Cluster`; use `ProxmoxCluster` for API calls:

```php
use Proxmox\Models\Cluster;

$cluster = Cluster::query()->create([
    'name' => 'hetzner',
    'url' => 'https://proxmox.example.com:8006/api2/json',
    'api_token_id' => 'laravel@pve!app',
    'api_token_secret' => $secret,
]);

$clusters = Cluster::query()->get(['id', 'name']);
```

Saving or deleting a connection record never calls Proxmox. API token secrets use Laravel's `encrypted` cast and are hidden from model arrays and JSON. Retain the encryption keys needed to read them.

The public classes have separate responsibilities:

| Class | Responsibility |
| --- | --- |
| `Proxmox\Facades\ProxmoxCluster` | Resolve a named or default API connection |
| `Proxmox\Models\Cluster` | Default Eloquent model for connection credentials and HTTP settings |
| `Proxmox\Proxmox` | Send authenticated HTTP requests and select live node/task contexts |
| `Proxmox\Resources\Nodes\Node` | Fetch node data and select, create, or list remote VMs |
| `Proxmox\Resources\VirtualMachines\VirtualMachine` | Read and manage a remote VM, with an optional in-memory status snapshot |
| `Proxmox\Resources\Tasks\Task` | Inspect or wait for remote background work |

Fetching nodes or VMs never creates local records. VM creation and deletion affect Proxmox; applications persist their own service records separately.

### Use a stored cluster directly

Build an API client from a cluster record:

```php
use Proxmox\Models\Cluster;

$nodes = Cluster::findOrFail(1)->client()->nodes();

$client = Cluster::findOrFail(1)->client();
$vms = $client->node('pve1')->virtualMachines();
```

`client()` returns a fresh `Proxmox\Proxmox` instance, the same API client type returned by `ProxmoxCluster::connection()`. It uses the loaded model's current attributes, including unsaved changes, without another database lookup or saving the record. Creating the client makes no HTTP request.

Call `$cluster->refresh()` before `client()` when you need to reload settings changed elsewhere. Custom cluster models inherit `client()` too.

### Custom cluster model

Extend the package model in your application to add cluster-specific relations or behavior:

```php
namespace App\Models;

use Proxmox\Models\Cluster as BaseCluster;

class Cluster extends BaseCluster
{
    // Your application's cluster relations and behavior.
}
```

Set its class in the published `config/proxmox.php`:

```php
'model' => App\Models\Cluster::class,
```

The default is `Proxmox\Models\Cluster::class`. The configured class must extend that model to inherit encrypted credentials, hidden secrets, connection defaults, `client()`, and `toConnectionConfig()`.

Connection lookups, `proxmox:create-cluster` (including duplicate-name checks), and `php artisan about --only=proxmox` use the configured model. Use your application's model for its own Eloquent queries too. Rebuild Laravel's configuration cache after changing this option if configuration is cached.

The inherited table is `clusters`. If you override the table or database connection, supply matching application migrations; the package migration still creates the default table.

### Connection settings

Each record stores these settings:

| Field | Default | Purpose |
| --- | --- | --- |
| `name` | Required | Unique connection name |
| `url` | Required | API base URL, including `/api2/json` |
| `api_token_id` | Required | Token identifier in `user@realm!token` form |
| `api_token_secret` | Required | Encrypted API token secret |
| `timeout` | `30` | HTTP request timeout in seconds |
| `connect_timeout` | `10` | Connection timeout in seconds |
| `verify_ssl` | `true` | Verify the server's TLS certificate |
| `ca_bundle` | `null` | CA certificate file path or PEM contents |
| `retry_times` | `0` | Extra GET attempts after connection failures |
| `retry_sleep` | `100` | Delay between retry attempts in milliseconds |

Keep certificate verification enabled. For a private Proxmox CA, set `ca_bundle` to a CA file path or PEM certificate. Inline PEM certificates require cURL support for `CURLOPT_CAINFO_BLOB`. The bundle is ignored when `verify_ssl` is `false`.

Timeouts must be positive. Retries apply only to GET requests after connection failures; writes and HTTP error responses are never retried automatically. Required HTTP settings are validated when a request is made.

### Default and runtime connections

`config/proxmox.php` contains the default stored connection name, initially `default`. Set it in your environment:

```dotenv
PROXMOX_CONNECTION=hetzner
```

```php
use Proxmox\Facades\ProxmoxCluster;

$connection = ProxmoxCluster::connection(); // Uses PROXMOX_CONNECTION.
$backup = ProxmoxCluster::connection('backup');
```

Facade shortcuts such as `ProxmoxCluster::nodes()` and injecting `Proxmox\Proxmox` use the default connection. If configuration is cached, rebuild it after changing `PROXMOX_CONNECTION`.

Every `ProxmoxCluster::connection($name)` call reads the stored cluster settings and builds a fresh client. Subsequent lookups see saved changes, renames, and deletions automatically, including changes made through bulk updates or other processes. A returned client retains the settings it was created with; resolve a new connection to use updated settings. Container injection of `Proxmox\Proxmox` also resolves a fresh default client.

For temporary connections or tests, supply settings directly:

```php
$connection = ProxmoxCluster::connectUsing('temporary', [
    'url' => 'https://lab.example.com:8006/api2/json',
    'api_token_id' => 'laravel@pve!app',
    'api_token_secret' => $secret,
]);
```

`connectUsing()` returns a standalone client without saving a database record or registering a connection name. Use the returned client for subsequent operations; `ProxmoxCluster::connection('temporary')` still looks for a stored cluster named `temporary`. Runtime settings accept `timeout`, `connect_timeout`, `verify_ssl`, `ca_bundle`, and `retry` (`times` and `sleep`). Query `Cluster` to list all stored connections. `ProxmoxCluster::setDefaultConnection('backup')` changes the default for the current application instance.

Run `php artisan about --only=proxmox` to inspect connection names without displaying secrets.

## Reading nodes and virtual machines

Listing methods fetch immediately and return plain arrays with the API's `data` envelope removed:

```php
use Proxmox\Facades\ProxmoxCluster;

$connection = ProxmoxCluster::connection('hetzner');
$nodes = $connection->nodes();
$vms = $connection->node('pve1')->virtualMachines();

$onlineNodes = collect($nodes)->where('status', 'online')->pluck('node');
$runningVms = collect($vms)->where('status', 'running')->values()->all();
```

Empty listings return `[]`. Collection operations filter the fetched data locally without additional requests. VM listing rows contain summary fields; fetch an individual VM for its runtime status and management methods.

### VM API resources and snapshots

Selecting a node or VM returns an API resource. `refresh()` fetches an in-memory status snapshot; `toArray()` and JSON serialize that snapshot. These operations do not read or write application node/VM records.

```php
$node = ProxmoxCluster::connection('hetzner')->node('pve1');
$vm = $node->virtualMachine(101); // No HTTP request yet.

$current = $vm->status(); // Fetches status; leaves the snapshot unchanged.
$vm->refresh();           // Fetches status and replaces the in-memory snapshot.
$snapshot = $vm->toArray();
$response = response()->json($vm);

$configuration = $vm->config();
$nodeStatus = $node->status();
```

`virtualMachine($vmid)` returns a `Proxmox\Resources\VirtualMachines\VirtualMachine` without fetching it. Its snapshot is initially empty; call `refresh()` to populate it. VM resources expose their selected `$vm->node` and `$vm->vmid`. Power and configuration operations leave the in-memory snapshot unchanged.

Unwrapped API attributes have associative keys sorted alphabetically at every nesting level. List order and field values are preserved, including in VM snapshots and JSON.

Node names and VMIDs are validated before requests. VMIDs must be integers or integer strings between `100` and `999999999`. Fetching a missing VM raises an HTTP exception.

### Guest network information and uptime

```php
$addresses = $vm->networkInfo();
// ['ipv4' => ['192.0.2.10'], 'ipv6' => ['2001:db8::10']]

$username = $vm->username();
$startup = $vm->startup();
// ['uptime' => 3600, 'started_at' => 1700000000]
```

`networkInfo()` uses the QEMU guest agent, which must be enabled in Proxmox and installed and running inside the guest. When the agent is unavailable, both address lists are `null`. Address lists exclude loopback and link-local addresses, include private addresses, and remove duplicates. Permission errors still throw.

`username()` reads the Cloud-Init `ciuser`, falling back to `root` for known Linux OS types or `Administrator` for Windows OS types. Unknown types without a configured user return `null`. This is configuration information, not a verification of guest credentials.

`startup()` fetches fresh status without changing the snapshot or requiring a guest agent. It returns uptime in seconds and an estimated startup Unix timestamp calculated from application time minus uptime. A stopped VM returns `['uptime' => 0, 'started_at' => null]`. This describes the QEMU process lifetime; keep application and Proxmox clocks synchronized when displaying the estimate.

## Resources and usage history

Use categorized resource methods for dashboards and capacity information:

```php
$connection = ProxmoxCluster::connection('hetzner');
$vm = $connection->node('pve1')->virtualMachine(101);

$status = $vm->status();
$usage = $vm->usage(status: $status);
$allocation = $vm->allocatedResources();
$available = $vm->availableResources(status: $status);
$startup = $vm->startup(status: $status);

$node = $connection->node('pve1');
$capacity = $node->resources();
$storages = $node->storages('images');
```

By default, each call fetches fresh data. Pass an explicitly fetched status to `usage()`, `availableResources()`, or `startup()` to reuse it; `availableResources()` still fetches disk and guest-agent data as needed. A reused status reflects when it was fetched, so use a recent status for an accurate startup estimate. Memory values are in MiB and disk sizes are in GiB, rounded down. Raw API status and listing fields retain their original API units.

| Method | Returned information |
| --- | --- |
| `$vm->usage()` | `status`, `uptime`, CPU `vcpus` and `percent`, memory `total`/`used`/`free`, network `in`/`out`, and disk `read`/`write` |
| `$vm->allocatedResources()` | Applied CPU `sockets`/`cores`/`vcpus`, memory `size`/`balloon`, and disks keyed by device with `storage` and `size` |
| `$vm->availableResources()` | CPU `vcpus`/`used`/`available`, memory `total`/`used`/`available`, per-disk usage, and guest filesystems |
| `$node->resources()` | CPU `cores`/`percent`/`allocated` and memory `total`/`used`/`free`/`allocated` across non-template QEMU VMs |
| `$node->storages('images')` | Enabled storages that support VM disks, keyed by name, with `type`, `content`, `shared`, `active`, `total`, `used`, and `available` |

CPU usage is expressed as a percentage. Network and disk I/O from `usage()` are cumulative byte counters since the VM started. `allocatedResources()` reads applied configuration and ignores pending changes; memory `balloon` is the minimum ballooning allocation, or `null` when disabled. CD-ROM and Cloud-Init drives are excluded from VM disks. The former VM `resources()` method remains available as a deprecated alias.

`availableResources()` is limited to the VM's allocation and volumes. CPU availability is a percentage of its vCPUs. Available memory uses guest-reported free memory when provided, otherwise allocation minus host-side usage; stopped VMs report their full memory allocation as available. It provides two disk views:

- `disks`: keyed by disk device, with `storage`, `total`, `used`, and `available`. These compare virtual disk size with storage volume usage and work without the guest agent. `used` and `available` are `null` when usage is unavailable, including for passthrough disks. These values do not measure free space inside the guest filesystem.
- `filesystems`: guest-agent entries with `mountpoint`, `type`, `total`, `used`, and `available`. The value is `null` when the VM is stopped or the agent is disabled or unavailable.

Node resource summaries include allocated CPU and memory for all non-template QEMU VMs, so allocation can exceed host capacity when overcommitted. Omit the content argument from `storages()` to list all enabled storages. Keep node capacity and storage summaries in administrator views.

### Usage history

```php
$cpu = $vm->cpuHistory();
$memory = $vm->memoryHistory('day');
$network = $vm->networkHistory('week');
$disk = $vm->diskHistory('month', 'max');

// Fetch all four series for the same timeframe with one request.
$history = $vm->usageHistory('day', 'max');
$cpu = $history['cpu'];
$memory = $history['memory'];
$network = $history['network'];
$disk = $history['disk'];
```

Each method returns an array of Proxmox RRD data points:

| Method | Point fields | Units |
| --- | --- | --- |
| `cpuHistory()` | `time`, `vcpus`, `percent` | Unix timestamp, vCPU count, percent |
| `memoryHistory()` | `time`, `total`, `used` | Unix timestamp, MiB |
| `networkHistory()` | `time`, `in`, `out` | Unix timestamp, bytes per second |
| `diskHistory()` | `time`, `read`, `write` | Unix timestamp, bytes per second |

The individual methods and `usageHistory()` accept `timeframe` (`hour`, `day`, `week`, `month`, or `year`) and `consolidation` (`average` or `max`). Defaults are `hour` and `average`. `usageHistory()` returns the same four series under `cpu`, `memory`, `network`, and `disk` keys. Missing measurements are `null`; preserve them when plotting gaps in history.

## Managing virtual machines

All power and configuration operations return a `Proxmox\Resources\Tasks\Task`. Call `wait()` when subsequent work depends on completion:

```php
$vm = ProxmoxCluster::connection('hetzner')->node('pve1')->virtualMachine(101);

$vm->start()->wait();
$vm->shutdown(timeout: 60)->wait();
```

| Method | Behavior |
| --- | --- |
| `start()` | Starts the VM |
| `shutdown(?int $timeout = null)` | Requests a graceful guest shutdown |
| `restart(?int $timeout = null)` | Requests a graceful reboot and applies pending VM configuration |
| `pause()` | Suspends execution while retaining state in memory |
| `hibernate()` | Saves state to disk; requires VM state storage |
| `resume()` | Resumes a paused or hibernated VM |
| `stop()` | Immediately powers off the VM |
| `reset()` | Immediately resets the VM |

The optional shutdown/restart timeout is a non-negative number of seconds sent to Proxmox. It is separate from the timeout passed to `Task::wait()`. Immediate power-off and reset can cause guest data loss.

### CPU, memory, and disks

```php
$vm->setCpu(cores: 4, sockets: 1)->wait();
$vm->setCores(6)->wait();          // Changes cores per socket; preserves socket count.
$vm->setMemory(mebibytes: 8192)->wait();
$vm->growDisk(disk: 'scsi0', gibibytes: 10)->wait();

$vm->refresh();
```

Values must be positive integers. `setCpu()` preserves the current socket count when `sockets` is omitted. Without the relevant hotplug support, CPU and memory changes can remain pending until the VM restarts.

`growDisk()` increases an existing disk by the supplied amount. It does not attach a disk, and Proxmox cannot shrink disks. Expanding guest partitions and filesystems remains the guest's responsibility.

### Deleting a VM

Stop a running VM before requesting destruction:

```php
if (($vm->status()['status'] ?? null) !== 'stopped') {
    $vm->stop()->wait();
}

$vm->delete()->wait();
```

`delete(bool $purge = true)` destroys the VM and its unreferenced disks. With `purge: true`, it also requests removal from backup jobs, replication, and HA. Passing `purge: false` still destroys the VM and disks. Applications that store server records should update them separately after remote destruction succeeds.

## Creating virtual machines

Pass a configuration array to a node's `createVirtualMachine()` method:

```php
use Proxmox\Facades\ProxmoxCluster;

$client = ProxmoxCluster::connection('hetzner');
$vmid = $client->nextVirtualMachineId();

$task = $client->node('pve1')->createVirtualMachine([
    'vmid' => $vmid,
    'name' => 'web-'.$vmid,
    'sockets' => 1,
    'cores' => 2,
    'memory' => 4096,
    'storage' => 'local-lvm',
    'disk' => 32,
    'bridge' => 'vmbr0',
]);

$task->wait();
```

This creates a VM with an empty disk. Use a cloud image or native installation parameters to provide an operating system. `nextVirtualMachineId()` fetches a free cluster VMID and validates it is between `100` and `999999999`; malformed or out-of-range responses raise `UnexpectedResponseException`. Your application must handle collisions between concurrent provisioning requests.

### Readable configuration fields

The method accepts the common fields below and passes additional native QEMU parameters through to Proxmox. Readable disk, network, image, and credential fields are translated to native parameters:

| Field | Meaning and dependencies |
| --- | --- |
| `vmid` | Required integer or integer string, from `100` to `999999999` |
| `name` | VM name |
| `sockets`, `cores` | CPU sockets and cores per socket |
| `memory` | Memory in MiB |
| `storage` | Destination storage for readable disk and Cloud-Init fields |
| `disk` | Positive integer disk size in GiB; requires `storage` |
| `image` | Importable QCOW2 volume ID; requires `storage` |
| `bridge` | Bridge name; creates a VirtIO `net0` card |
| `ip` | IPv4 address without a prefix, or `dhcp`; requires `storage` and `bridge` or native `net0` |
| `prefix` | Integer from `0` to `32`, required for a static `ip` |
| `gateway` | Optional IPv4 gateway for a static `ip` |
| `user`, `password` | Cloud-Init guest credentials; require `storage` |
| `ssh_key` | Unencoded OpenSSH public key text; requires `storage` |

`image`, `ip`, `user`, `password`, and `ssh_key` add a Cloud-Init drive at `ide2`. Use an image with Cloud-Init support for guest initialization. Separate multiple public keys with newlines; the package URL-encodes `ssh_key` for Proxmox.

For DHCP, use `'ip' => 'dhcp'` and omit `prefix` and `gateway`. Readable IP fields support IPv4; use native `ipconfig0` for IPv6 or other native network settings.

Do not combine readable fields with their native equivalents in the same configuration:

| Readable field | Native equivalent |
| --- | --- |
| `disk` or `image` | `scsi0` |
| `bridge` | `net0` |
| `ip` | `ipconfig0` |
| `user` | `ciuser` |
| `password` | `cipassword` |
| `ssh_key` | `sshkeys` |

Readable Cloud-Init fields also conflict with an explicit `ide2`. Native credentials and `sshkeys` pass through unchanged; encode native SSH keys yourself. Other native options, such as additional disks, `ostype`, `onboot`, `boot`, and `scsihw`, can be supplied directly.

### Creating from a QCOW2 cloud image

List QCOW2 sources on Proxmox storage with the `import` content type enabled:

```php
$images = ProxmoxCluster::connection('hetzner')->node('pve1')->images('local');
$volumeIds = collect($images)->pluck('volid');
```

The source file must already exist on Proxmox storage. Use its volume ID as `image`; the package does not download or upload images.

```php
// $publicKey contains the text of an OpenSSH public key.
$client = ProxmoxCluster::connection('hetzner');
$vmid = $client->nextVirtualMachineId();

$task = $client->node('pve1')->createVirtualMachine([
    'vmid' => $vmid,
    'name' => 'ubuntu-'.$vmid,
    'cores' => 2,
    'memory' => 4096,
    'image' => 'local:import/ubuntu.qcow2',
    'storage' => 'local-lvm',
    'disk' => 32,
    'bridge' => 'vmbr0',
    'ip' => '192.0.2.10',
    'prefix' => 24,
    'gateway' => '192.0.2.1',
    'user' => 'ubuntu',
    'ssh_key' => $publicKey,
], importTimeout: 300);

$task->wait(timeout: 300);
$client->node('pve1')->virtualMachine($vmid)->start()->wait();
```

The image must be an import volume such as `local:import/ubuntu.qcow2`. A download URL, Laravel-local path, ISO, or an existing VM disk is not accepted as an image source. Proxmox imports the source into a new VM disk. Defaults include `virtio-scsi-pci`, booting from `scsi0`, and a serial console; explicit native boot, controller, and console settings are preserved.

Task behavior depends on the fields supplied:

| Configuration | What `createVirtualMachine()` returns |
| --- | --- |
| Without `image` | The VM creation task |
| `image` without `disk` | The creation/import task; retains the image's virtual disk size |
| Both `image` and `disk` | Waits for import, submits an absolute-size disk resize, and returns the resize task |

With `image` and `disk`, `createVirtualMachine()` blocks while waiting for import, up to `importTimeout` seconds (default `300`). Run this workflow in a queued job. Wait for the returned resize task before starting the VM; enabling `start` in this configuration is rejected. The requested disk size must be at least the image's virtual disk size.

HTTP request timeouts, the import wait timeout, and a returned task's wait timeout are separate settings. Failed or timed-out provisioning does not cancel remote tasks or automatically delete a VM. Inspect Proxmox before retrying a failed operation.

## Background tasks

Creation, power operations, configuration changes, disk resizing, and VM deletion return task objects:

```php
$task = $vm->start();

$upid = $task->upid;
$nodeName = $task->node;
$status = $task->status();
$running = $task->isRunning();
$lines = $task->log(start: 0, limit: 50);

$task->wait(timeout: 300, interval: 1);
```

`status()` returns an array, `isRunning()` fetches the current task state, and `log()` returns an array of log entries with pagination. `wait()` polls until completion and returns the same task. Both timeout and interval are positive integers in seconds; defaults are `300` and `1`.

Rebuild a task from a saved UPID using the connection that created it:

```php
$task = ProxmoxCluster::connection('backup')->task($upid);
$task->wait();

// Or select the node explicitly:
$task = ProxmoxCluster::connection('backup')->node('pve1')->task($upid);
```

`task($upid)` reads the node from the full `UPID:node:...` value. Casting a task to a string returns its UPID. Save the connection name alongside the UPID when tracking work across multiple clusters.

Tasks with exit status `OK` or `WARNINGS: n` count as successful. Other exit statuses raise `TaskFailedException`. A polling timeout raises `TaskTimeoutException` and leaves the remote task running.

## Application storage

Nodes, VMs, customer ownership, and service lifecycles belong to the consuming application. Fetch arrays and store or cache the fields your application needs:

```php
use Illuminate\Support\Facades\Cache;
use Proxmox\Facades\ProxmoxCluster;

$connection = ProxmoxCluster::connection('hetzner');
$nodes = Cache::remember('proxmox:hetzner:nodes', now()->addMinute(), fn () => $connection->nodes());

// An application-owned server record provides the routing information.
$vm = ProxmoxCluster::connection($server->proxmox_connection)
    ->node($server->proxmox_node)
    ->virtualMachine($server->proxmox_vmid);

$status = $vm->status();
```

The `$server` above is an application-defined record; its field names and relations are your choice. Save connection names, node names, VMIDs, and any business fields your application needs. Cache returned arrays rather than connection or resource objects.

Authorize access through your application's records before selecting a VM. Treat the stored node as current placement and update it if the VM moves. Keep an application record ID for each service lifecycle; Proxmox can reuse VMIDs after deletion. Remote operations and local writes are independent, so the application handles provisioning failures and reconciliation. Application model creation/deletion and remote VM creation/deletion are separate operations.

## Raw HTTP requests

Use the underlying client for endpoints beyond the resource API or when you need response headers and status codes:

```php
use Proxmox\Facades\ProxmoxCluster;

$connection = ProxmoxCluster::connection('hetzner');
$response = $connection->get('cluster/resources', ['type' => 'vm']);
$resources = $response->throw()->json('data');

$version = $connection->request('GET', 'version')->throw()->json('data');
```

`get()`, `post()`, `put()`, `delete()`, and `request()` return `Illuminate\Http\Client\Response`. Call `throw()` yourself to raise HTTP errors and unwrap `data` when needed. Endpoints must be non-empty paths relative to the configured API URL.

`get()` and `delete()` send parameters in the query string. `post()` and `put()` send form data. `request()` accepts Laravel HTTP request options; use `query` or `form_params` to select the parameter encoding explicitly.

## Error handling

Resource methods unwrap responses, validate their expected shape, and raise exceptions for failures:

| Exception | Meaning |
| --- | --- |
| `Illuminate\Http\Client\ConnectionException` | An HTTP connection failed |
| `Illuminate\Http\Client\RequestException` | Proxmox returned an HTTP error |
| `InvalidArgumentException` | An identifier or operation argument is invalid |
| `Proxmox\Exceptions\InvalidConfigurationException` | A stored cluster, configured model class, or required connection setting is missing or invalid |
| `Proxmox\Exceptions\UnexpectedResponseException` | Proxmox returned data in an unexpected shape |
| `Proxmox\Exceptions\TaskFailedException` | A waited task finished with an error; exposes `upid` and `exitStatus` |
| `Proxmox\Exceptions\TaskTimeoutException` | Task polling exceeded its timeout; exposes `upid` and `timeout` |

All four package exception classes implement `Proxmox\Exceptions\ProxmoxException`. Laravel HTTP exceptions and PHP argument exceptions remain separate. A task timeout stops local polling and does not cancel the task on Proxmox.

## Testing

Use Laravel's HTTP fakes with temporary connection settings to test API operations without a Proxmox server or stored connection:

```php
use Illuminate\Support\Facades\Http;
use Proxmox\Facades\ProxmoxCluster;

it('lists virtual machines on a node', function () {
    $connection = ProxmoxCluster::connectUsing('testing', [
        'url' => 'https://proxmox.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!test',
        'api_token_secret' => 'test-secret',
    ]);

    Http::preventStrayRequests();
    Http::fake(['*/nodes/pve1/qemu' => Http::response(['data' => [['vmid' => 101]]])]);

    expect($connection->node('pve1')->virtualMachines())->toBe([['vmid' => 101]]);
    Http::assertSentCount(1);
});
```

To test stored connection resolution, run the package migration and create a record through the configured cluster model in your test database. Node/VM API tests need no package node or VM records. Fake task status endpoints when waiting for tasks, including `status: stopped` and `exitstatus: OK` for a successful result.

### Package development

After cloning the repository, run `composer install`. Available checks and workbench commands are:

| Command | Purpose |
| --- | --- |
| `composer test` | Static analysis, formatting check, type coverage, and Pest tests |
| `composer analyse` | PHPStan analysis |
| `composer lint:check` | Check PHP formatting |
| `composer lint` | Format PHP files |
| `composer test:types` | Check type coverage at the configured 100% minimum |
| `composer test:unit` | Run Pest tests in parallel |
| `composer build` | Build the Testbench workbench |
| `composer serve` | Build and serve the workbench |

## Upgrading

This version simplifies persistence to stored cluster connections. Update consumers before upgrading:

- Import `Proxmox\Facades\ProxmoxCluster` instead of `Proxmox\Facades\Cluster` or `Proxmox\Facades\Proxmox`. The auto-discovered facade alias is now `ProxmoxCluster`; the Eloquent model remains `Proxmox\Models\Cluster`.
- Store connections in `clusters`. Configuration-backed connections and the `api` / `inventory` modes have been removed. `config/proxmox.php` contains `default` and the cluster `model` class.
- Existing published migrations are not rewritten. For an earlier cluster table name, supply an application migration or point a custom cluster model at the existing table.
- Replace `node($name)->virtualMachines()->all()` with `node($name)->virtualMachines()`.
- Replace `node($name)->virtualMachines()->create($configuration)` with `node($name)->createVirtualMachine($configuration)`.
- Replace package Eloquent node/VM models, `HasVirtualMachines`, model resource accessors, and inventory resource factories with application-owned records and explicit `connection()->node()->virtualMachine()` selection.
- Replace scheduled node synchronization commands with application-owned fetching and persistence when needed.
- Remove calls to `purge()` and `getConnections()`. Named lookups read stored settings and return fresh clients; query the configured cluster model to list stored connections.
- Keep and use the client returned by `connectUsing()`. Runtime clients are independent of stored connection lookup and do not register their names.

The installer preserves previously published migrations and configuration. Existing node/VM tables and their data are left untouched. Migrate ownership and service data into your application's models before retiring old tables. Existing cluster connection records remain usable; the old `is_active` field is no longer used.

VM power, configuration, creation, metrics, snapshots, and task methods remain available. Creation and mutation methods return `Task` objects; wait for completion explicitly where needed. See [CHANGELOG.md](CHANGELOG.md) for release changes.

## Contributing

See the [contribution guide](.github/CONTRIBUTING.md) for the development workflow and run `composer test` before submitting changes.

Report vulnerabilities according to the [security policy](.github/SECURITY.md).

## Credits

- [Nima Asaadi](https://github.com/aboutnima)
- [Contributors](https://github.com/aboutnima/proxmox/graphs/contributors)

## License

Released under the [MIT license](LICENSE.md).
