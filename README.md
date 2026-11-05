# Proxmox

[![Latest version](https://img.shields.io/packagist/v/aboutnima/proxmox.svg?style=flat-square)](https://packagist.org/packages/aboutnima/proxmox)
[![PHP version](https://img.shields.io/packagist/php-v/aboutnima/proxmox.svg?style=flat-square)](https://packagist.org/packages/aboutnima/proxmox)
[![Tests](https://img.shields.io/github/actions/workflow/status/aboutnima/proxmox/tests.yml?branch=main&label=Tests&style=flat-square)](https://github.com/aboutnima/proxmox/actions/workflows/tests.yml)
[![License](https://img.shields.io/github/license/aboutnima/proxmox?style=flat-square)](LICENSE.md)

A Laravel package for the Proxmox VE REST API. Manage QEMU virtual machines through a fluent, node-scoped API, monitor resources, and track background tasks using Laravel's HTTP client.

Use it with connections in your application's configuration, or enable inventory mode to store clusters, nodes, virtual machines, and VM ownership in your database.

- API token authentication, named connections, custom certificate authorities, and configurable timeouts.
- VM creation from native QEMU parameters or readable fields, including QCOW2 cloud images and Cloud-Init.
- Power operations, CPU and memory changes, disk growth, and VM destruction.
- Runtime snapshots, guest information, resource summaries, and usage history.
- Optional Eloquent inventory with encrypted credentials, node synchronization, and polymorphic owners.

## Contents

- [Requirements](#requirements)
- [Installation and quick start](#installation-and-quick-start)
- [Configuration](#configuration)
- [Multiple connections](#multiple-connections)
- [Reading nodes and virtual machines](#reading-nodes-and-virtual-machines)
- [Resources and usage history](#resources-and-usage-history)
- [Managing virtual machines](#managing-virtual-machines)
- [Creating virtual machines](#creating-virtual-machines)
- [Background tasks](#background-tasks)
- [Inventory mode](#inventory-mode)
- [Raw HTTP requests](#raw-http-requests)
- [Error handling](#error-handling)
- [Testing](#testing)
- [Upgrading](#upgrading)
- [Contributing](#contributing)

## Requirements

| Requirement | Supported configuration |
| --- | --- |
| PHP | `^8.3` |
| Laravel | `^12.0` or `^13.0`, with a compatible PHP version |
| Proxmox | A reachable Proxmox VE JSON API and an API token permitted to perform your operations |
| Database | Required only for inventory mode |

The resource API manages QEMU VMs. For other Proxmox endpoints, use the [raw HTTP client](#raw-http-requests).

## Installation and quick start

Install the package in your Laravel application:

```bash
composer require aboutnima/proxmox
php artisan proxmox:install --mode=api
```

Laravel discovers the service provider and facade automatically. The installer publishes `config/proxmox.php` and sets `PROXMOX_MODE` in your application's `.env` file.

Add your connection settings to `.env`. Include `/api2/json` in the URL:

```dotenv
PROXMOX_MODE=api
PROXMOX_CONNECTION=default
PROXMOX_URL=https://proxmox.example.com:8006/api2/json
PROXMOX_API_TOKEN_ID="laravel@pve!app"
PROXMOX_API_TOKEN_SECRET=your-token-secret
```

List your nodes and inspect a VM, replacing the node name and VMID with your own:

```php
use Proxmox\Facades\Proxmox;

$nodes = Proxmox::nodes();

$node = Proxmox::node('pve1');
$virtualMachines = $node->virtualMachines()->all();

$vm = $node->virtualMachine(101);
$status = $vm->toArray();
$configuration = $vm->config();
```

Run `php artisan proxmox:install` without `--mode` to choose interactively:

| Mode | Connection source | Setup |
| --- | --- | --- |
| `api` (default) | `config/proxmox.php`, usually populated from `.env` | Publishes configuration; no database setup |
| `inventory` | Clusters stored in `proxmox_clusters` | Also publishes inventory migrations; see [inventory mode](#inventory-mode) |

Running the installer again preserves existing configuration and skips migrations that are already published. If configuration is cached, run `php artisan config:cache` after changing the mode or environment settings.

To publish configuration manually:

```bash
php artisan vendor:publish --tag=proxmox-config
```

## Configuration

The default connection reads these environment variables:

| Variable | Default | Purpose |
| --- | --- | --- |
| `PROXMOX_MODE` | `api` | Selects `api` or `inventory` mode |
| `PROXMOX_CONNECTION` | `default` | Default connection name, or database cluster name in inventory mode |
| `PROXMOX_URL` | Empty | API base URL, including `/api2/json` |
| `PROXMOX_API_TOKEN_ID` | Empty | Token identifier in `user@realm!token` form |
| `PROXMOX_API_TOKEN_SECRET` | Empty | API token secret |
| `PROXMOX_TIMEOUT` | `30` | HTTP request timeout in seconds |
| `PROXMOX_CONNECT_TIMEOUT` | `10` | Connection timeout in seconds |
| `PROXMOX_VERIFY_SSL` | `true` | Whether to verify the server's TLS certificate |
| `PROXMOX_CA_BUNDLE` | Unset | CA certificate file path or PEM certificate contents |
| `PROXMOX_RETRY_TIMES` | `0` | Extra GET attempts after a connection failure |
| `PROXMOX_RETRY_SLEEP` | `100` | Delay between GET attempts in milliseconds |

URL, token, and timeout settings are validated when a request is made, so missing credentials do not prevent the application from booting. Request and connection timeouts must be positive integers.

Retries apply only to GET requests after connection failures. HTTP error responses and write requests are never retried automatically.

### TLS certificates

Keep certificate verification enabled. For a private Proxmox CA, place its certificate on your application server and configure its path:

```dotenv
PROXMOX_CA_BUNDLE=/absolute/path/to/pve-root-ca.pem
```

You can also pass PEM certificate contents as a connection's `ca_bundle`. Inline PEM certificates require cURL support for `CURLOPT_CAINFO_BLOB`. The CA bundle is ignored when `verify_ssl` is `false`.

Run `php artisan about` to inspect the package mode, default connection, and available connection names without displaying token secrets.

## Multiple connections

Add named entries under `connections` in `config/proxmox.php`. Each entry accepts the same settings as `default`; map additional environment variables explicitly:

```php
// Inside the connections array in config/proxmox.php:
'backup' => [
    'url' => env('PROXMOX_BACKUP_URL'),
    'api_token_id' => env('PROXMOX_BACKUP_API_TOKEN_ID'),
    'api_token_secret' => env('PROXMOX_BACKUP_API_TOKEN_SECRET'),
    'timeout' => 30,
    'connect_timeout' => 10,
    'verify_ssl' => true,
    'ca_bundle' => env('PROXMOX_BACKUP_CA_BUNDLE'),
    'retry' => ['times' => 0, 'sleep' => 100],
],
```

Select a connection by name:

```php
use Proxmox\Facades\Proxmox;

$backup = Proxmox::connection('backup');
$nodes = $backup->nodes();
$vm = $backup->node('pve1')->virtualMachine(101);
```

Facade calls without `connection()` use `PROXMOX_CONNECTION`. Resolving or injecting `Proxmox\Proxmox` also gives you the default connection.

For connection settings supplied by your application at runtime:

```php
$client = Proxmox::connectUsing('lab', [
    'url' => 'https://lab.example.com:8006/api2/json',
    'api_token_id' => 'laravel@pve!app',
    'api_token_secret' => $secret,
]);

$nodes = $client->nodes();
```

Clients are cached by name. After changing configuration in a running process, call `Proxmox::purge('backup')` to rebuild that connection on its next use. `connectUsing()` replaces the cached client for its name. `Proxmox::setDefaultConnection('backup')` changes the default for the current application instance.

## Reading nodes and virtual machines

Listing methods fetch immediately and return plain arrays with the API's `data` envelope removed:

```php
use Proxmox\Facades\Proxmox;

$nodes = Proxmox::nodes();
$vms = Proxmox::node('pve1')->virtualMachines()->all();

$onlineNodes = collect($nodes)->where('status', 'online')->pluck('node');
$runningVms = collect($vms)->where('status', 'running')->values()->all();
```

Empty listings return `[]`. Collection operations filter the fetched data locally without additional requests. VM listing rows contain summary fields; fetch an individual VM for its runtime status and management methods.

### VM snapshots

```php
$node = Proxmox::node('pve1');             // Selects a node without an HTTP request.
$operations = $node->virtualMachines();   // Selects VM operations without an HTTP request.

$vm = $node->virtualMachine(101);         // Fetches runtime status immediately.
$snapshot = $vm->toArray();               // Uses the stored snapshot.
$response = response()->json($vm);       // Serializes the same snapshot.

$current = $vm->status();                 // Fetches status; leaves the snapshot unchanged.
$vm->refresh();                          // Fetches status and replaces the snapshot.
$configuration = $vm->config();          // Fetches VM configuration separately.
$nodeStatus = $node->status();            // Fetches node status.
```

VM resources expose their selected `$vm->node` and `$vm->vmid`. Power and configuration operations leave the stored snapshot unchanged; call `refresh()` when you need updated data.

Unwrapped API attributes have associative keys sorted alphabetically at every nesting level. List order and field values are preserved, including in VM snapshots and JSON.

Node names and VMIDs are validated before requests. VMIDs must be integers or integer strings between `100` and `999999999`. Selecting a missing VM raises an HTTP exception.

### Guest information and uptime

```php
$os = $vm->osInfo();
$osName = $os['pretty-name'] ?? null;

$addresses = $vm->networkInfo();
// ['ipv4' => ['192.0.2.10'], 'ipv6' => ['2001:db8::10']]

$username = $vm->username();
$startup = $vm->startup();
// ['uptime' => 3600, 'started_at' => 1700000000]
```

`osInfo()` and `networkInfo()` use the QEMU guest agent, which must be enabled in Proxmox and installed and running inside the guest. When the agent is unavailable, OS information is `null` and both address lists are `null`. Address lists exclude loopback and link-local addresses, include private addresses, and remove duplicates. Permission errors still throw.

`username()` reads the Cloud-Init `ciuser`, falling back to `root` for known Linux OS types or `Administrator` for Windows OS types. Unknown types without a configured user return `null`. This is configuration information, not a verification of guest credentials.

`startup()` fetches fresh status without changing the snapshot or requiring a guest agent. It returns uptime in seconds and an estimated startup Unix timestamp calculated from application time minus uptime. A stopped VM returns `['uptime' => 0, 'started_at' => null]`. This describes the QEMU process lifetime; keep application and Proxmox clocks synchronized when displaying the estimate.

## Resources and usage history

Use categorized resource methods for dashboards and capacity information:

```php
$vm = Proxmox::node('pve1')->virtualMachine(101);

$usage = $vm->usage();
$allocation = $vm->resources();
$available = $vm->availableResources();

$node = Proxmox::node('pve1');
$capacity = $node->resources();
$storages = $node->storages('images');
```

Each call fetches fresh data. Memory values are in MiB and disk sizes are in GiB, rounded down. Raw API status and listing fields retain their original API units.

| Method | Returned information |
| --- | --- |
| `$vm->usage()` | `status`, `uptime`, CPU `vcpus` and `percent`, memory `total`/`used`/`free`, network `in`/`out`, and disk `read`/`write` |
| `$vm->resources()` | Applied CPU `sockets`/`cores`/`vcpus`, memory `size`/`balloon`, and disks keyed by device with `storage` and `size` |
| `$vm->availableResources()` | CPU `vcpus`/`used`/`available`, memory `total`/`used`/`available`, per-disk usage, and guest filesystems |
| `$node->resources()` | CPU `cores`/`percent`/`allocated` and memory `total`/`used`/`free`/`allocated` across non-template QEMU VMs |
| `$node->storages('images')` | Enabled storages that support VM disks, keyed by name, with `type`, `content`, `shared`, `active`, `total`, `used`, and `available` |

CPU usage is expressed as a percentage. Network and disk I/O from `usage()` are cumulative byte counters since the VM started. `resources()` reads applied configuration and ignores pending changes; memory `balloon` is the minimum ballooning allocation, or `null` when disabled. CD-ROM and Cloud-Init drives are excluded from VM disks.

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
```

Each method returns an array of Proxmox RRD data points:

| Method | Point fields | Units |
| --- | --- | --- |
| `cpuHistory()` | `time`, `vcpus`, `percent` | Unix timestamp, vCPU count, percent |
| `memoryHistory()` | `time`, `total`, `used` | Unix timestamp, MiB |
| `networkHistory()` | `time`, `in`, `out` | Unix timestamp, bytes per second |
| `diskHistory()` | `time`, `read`, `write` | Unix timestamp, bytes per second |

All four methods accept `timeframe` (`hour`, `day`, `week`, `month`, or `year`) and `consolidation` (`average` or `max`). Defaults are `hour` and `average`. Missing measurements are `null`; preserve them when plotting gaps in history.

## Managing virtual machines

All power and configuration operations return a `Proxmox\Resources\Tasks\Task`. Call `wait()` when subsequent work depends on completion:

```php
$vm = Proxmox::node('pve1')->virtualMachine(101);

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
$vm->setMemory(8192)->wait();      // MiB.
$vm->growDisk('scsi0', 10)->wait(); // Adds 10 GiB to the existing disk.

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

`delete(bool $purge = true)` destroys the VM and its unreferenced disks. With `purge: true`, it also requests removal from backup jobs, replication, and HA. Passing `purge: false` still destroys the VM and disks. In inventory mode, delete the database record separately after remote destruction succeeds.

## Creating virtual machines

Pass a configuration array to a node's `virtualMachines()->create()` method:

```php
use Proxmox\Facades\Proxmox;

$client = Proxmox::connection();
$vmid = $client->nextVirtualMachineId();

$task = $client->node('pve1')->virtualMachines()->create([
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

This creates a VM with an empty disk. Use a cloud image or native installation parameters to provide an operating system. `nextVirtualMachineId()` fetches a free cluster VMID; your application must handle collisions between concurrent provisioning requests.

### Readable configuration fields

Additional native QEMU parameters are passed through to Proxmox. The package translates these readable fields:

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
$images = Proxmox::node('pve1')->images('local');
$volumeIds = collect($images)->pluck('volid');
```

The source file must already exist on Proxmox storage. Use its volume ID as `image`; the package does not download or upload images.

```php
// $publicKey contains the text of an OpenSSH public key.
$client = Proxmox::connection();
$vmid = $client->nextVirtualMachineId();

$task = $client->node('pve1')->virtualMachines()->create([
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

| Configuration | What `create()` returns |
| --- | --- |
| Without `image` | The VM creation task |
| `image` without `disk` | The creation/import task; retains the image's virtual disk size |
| Both `image` and `disk` | Waits for import, submits an absolute-size disk resize, and returns the resize task |

With `image` and `disk`, `create()` blocks while waiting for import, up to `importTimeout` seconds (default `300`). Run this workflow in a queued job. Wait for the returned resize task before starting the VM; enabling `start` in this configuration is rejected. The requested disk size must be at least the image's virtual disk size.

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
$task = Proxmox::connection('backup')->task($upid);
$task->wait();

// Or select the node explicitly:
$task = Proxmox::connection('backup')->node('pve1')->task($upid);
```

`task($upid)` reads the node from the full `UPID:node:...` value. Casting a task to a string returns its UPID. Save the connection name alongside the UPID when tracking work across multiple clusters.

Tasks with exit status `OK` or `WARNINGS: n` count as successful. Other exit statuses raise `TaskFailedException`. A polling timeout raises `TaskTimeoutException` and leaves the remote task running.

## Inventory mode

Inventory mode adds database records for clusters, nodes, and VM ownership while using the same API resources for live operations.

```bash
php artisan proxmox:install --mode=inventory --migrate
```

The installer publishes a single `create_proxmox_tables` migration and, with `--migrate`, runs Laravel's pending migrations. Without that flag, you can run `php artisan migrate` separately. For manual publication, use `php artisan vendor:publish --tag=proxmox-migrations`; use the installer for repeatable setup that skips already-published migrations.

| Table | Contents |
| --- | --- |
| `proxmox_clusters` | Named connections, encrypted API token secrets, HTTP/TLS/retry settings, and `is_active` |
| `proxmox_nodes` | Cluster nodes, unique by cluster and name, with their last synchronized status |
| `proxmox_virtual_machines` | VM lifecycle records with cluster, current node, VMID, optional name/status, polymorphic owner, and soft deletes |

In this mode, named connections resolve clusters from the database. Entries under `proxmox.connections` are not used for normal connection resolution. Set `PROXMOX_CONNECTION` to an existing cluster name if it differs from `default`.

### Adding clusters

Create a cluster interactively:

```bash
php artisan proxmox:create-cluster default
```

You can provide non-secret settings as options; the API token secret is requested through a hidden prompt:

```bash
php artisan proxmox:create-cluster eu-1 \
    --url=https://eu.example.com:8006/api2/json \
    --token-id='laravel@pve!app' \
    --ca-bundle=/absolute/path/to/pve-root-ca.pem
```

Supported options include `--timeout=30`, `--connect-timeout=10`, `--inactive`, and `--insecure` to disable TLS verification. There is no token-secret command-line option. Cluster names must contain 1–100 letters, numbers, hyphens, or underscores and must be unique.

Move an existing configured connection into inventory:

```bash
php artisan proxmox:create-cluster default --from-env
php artisan proxmox:create-cluster eu-1 --from-env --connection=default
```

`--from-env` reads `config/proxmox.php`, including environment-backed values, and copies the selected connection. Its default source name is the new cluster name. Remove copied token secrets from `.env` once they are stored in inventory.

Use a stored cluster through the facade or its model:

```php
use Proxmox\Facades\Proxmox;
use Proxmox\Models\Cluster;

$nodes = Proxmox::connection('eu-1')->nodes();

$cluster = Cluster::query()->where('name', 'eu-1')->firstOrFail();
$vms = $cluster->client()->node('pve1')->virtualMachines()->all();

$activeClusters = Cluster::query()->active()->get();
$cluster->update(['is_active' => false]);
```

Saving or deleting a cluster model clears its cached connection for the next resolution. `is_active` is an application selection flag; inactive clusters remain accessible by name. Node synchronization with `--all` selects active clusters only.

### Stored credentials

`api_token_secret` uses Laravel's `encrypted` cast and is hidden from model arrays and JSON. Keep `APP_KEY` available to every application instance that reads these records, and retain the necessary keys when rotating encryption keys. Losing the encryption key prevents decryption of stored secrets.

`--ca-bundle` reads the certificate file and stores its PEM contents with the cluster, so other application instances do not need the original file. Inline certificate verification requires the cURL support described in [TLS certificates](#tls-certificates).

### Synchronizing nodes

```bash
php artisan proxmox:sync-nodes
php artisan proxmox:sync-nodes eu-1 us-1
php artisan proxmox:sync-nodes --all
```

Without arguments, the command prompts for clusters and preselects active ones. Named clusters are synchronized even when inactive. `--all` synchronizes every active cluster and supports non-interactive scheduling:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('proxmox:sync-nodes --all')
    ->everyFiveMinutes()
    ->withoutOverlapping();
```

New nodes are added and existing nodes receive the status returned by Proxmox. Nodes absent from a listing are retained with `Proxmox\Models\Node::MISSING` (`missing`) because VM records may still refer to them. Reappearing nodes receive their current remote status.

Call `$cluster->syncNodes()` in application code to receive node names grouped under `added`, `updated`, `unchanged`, and `missing`. Invalid listings leave that cluster's node inventory unchanged. If a cluster fails during the command, processing continues for the others and the command exits with a failure code. This command synchronizes nodes; VM inventory is recorded explicitly.

### VM owners

Add `HasVirtualMachines` to an owner model, such as a user or team:

```php
namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Proxmox\Concerns\HasVirtualMachines;

class User extends Authenticatable
{
    use HasVirtualMachines;
}
```

The trait provides `$user->virtualMachines()` and `$user->virtualMachines`. Each VM record has `owner`, `cluster`, and `node` relations. The migration's owner columns respect `Schema::morphUsingUuids()` or `Schema::morphUsingUlids()` if configured before migration.

Each VM inventory row represents one lifecycle. Since Proxmox can reuse a VMID after deletion, use the inventory row ID when identifying a VM over time.

### Provisioning and recording a VM

Eloquent VM operations update the database only. Provision the remote VM, wait for completion, and then create its inventory record:

```php
use Proxmox\Models\Cluster;

// $user is an owner model using HasVirtualMachines.
$cluster = Cluster::query()->where('name', 'eu-1')->firstOrFail();
$node = $cluster->nodes()->where('name', 'pve1')->firstOrFail();
$client = $node->cluster->client();
$vmid = $client->nextVirtualMachineId();

$client->node($node->name)->virtualMachines()->create([
    'vmid' => $vmid,
    'name' => 'web-'.$vmid,
    'cores' => 2,
    'memory' => 4096,
    'storage' => 'local-lvm',
    'image' => 'local:import/ubuntu.qcow2',
    'disk' => 32,
    'bridge' => 'vmbr0',
    'ip' => 'dhcp',
    'user' => 'ubuntu',
    'ssh_key' => $publicKey,
])->wait();

$record = $user->virtualMachines()->create([
    'cluster_id' => $node->cluster_id,
    'node_id' => $node->id,
    'vmid' => $vmid,
    'name' => 'web-'.$vmid,
]);

$vm = $record->client();
$vm->start()->wait();
```

Use the node's cluster client for provisioning so requests target the same cluster as the inventory record. API settings such as `cores`, `memory`, `image`, and credentials belong in the creation configuration, not Eloquent attributes.

To record an existing VM, create an inventory record with its `cluster_id`, `node_id`, and `vmid` through Eloquent. Creating the record never provisions a remote VM.

### Managing a recorded VM

`Proxmox\Models\VirtualMachine` is the database record. `Proxmox\Resources\VirtualMachines\VirtualMachineResource` is the live API resource:

```php
use Proxmox\Resources\Nodes\NodeResource;
use Proxmox\Resources\VirtualMachines\VirtualMachineResource;

$record = $user->virtualMachines()->firstOrFail();
$vm = VirtualMachineResource::make($record);

$vm->setMemory(8192)->wait();
$status = $vm->refresh()->toArray();

$nodeResource = NodeResource::make($record->node);
$nodeStatus = $nodeResource->status();
```

Inventory clusters, nodes, and VMs also expose `client()` and `resource()` as equivalent entry points. A cluster returns its Proxmox client, a node returns its node-scoped resource, and a VM returns its VM resource. These methods and the factories select the API context without making an HTTP request. The VM factory requires a saved, non-deleted record with a node belonging to the same cluster. Call `refresh()` to populate the VM resource's initially empty snapshot. Live operations never update the inventory record.

Destroy the remote VM and delete the inventory separately:

```php
if (($vm->status()['status'] ?? null) !== 'stopped') {
    $vm->stop()->wait();
}

$vm->delete()->wait(); // Destroys the remote VM and disks.
$record->delete();    // Soft deletes the database row.
```

Creating, updating, deleting, restoring, or force deleting an inventory record never calls Proxmox. Remote operations and database writes are independent; handle VMID collisions, task failures, missing remote VMs, and database failures in your application. There is no automatic provisioning retry, cleanup, or reconciliation.

### Custom inventory models

Extend a package model to add application behavior:

```php
namespace App\Models;

class ProxmoxCluster extends \Proxmox\Models\Cluster
{
    // Add application-specific relations or behavior.
}
```

Register your classes under `models` in `config/proxmox.php`:

```php
// Inside the models array in config/proxmox.php:
'cluster' => App\Models\ProxmoxCluster::class,
'node' => Proxmox\Models\Node::class,
'virtual_machine' => Proxmox\Models\VirtualMachine::class,
```

Each class must extend its corresponding package model. The manager, relations, and ownership trait use the configured classes.

## Raw HTTP requests

Use the underlying client for endpoints beyond the resource API or when you need response headers and status codes:

```php
use Proxmox\Facades\Proxmox;

$response = Proxmox::get('cluster/resources', ['type' => 'vm']);
$resources = $response->throw()->json('data');

$version = Proxmox::request('GET', 'version')->throw()->json('data');
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
| `Proxmox\Exceptions\InvalidConfigurationException` | A connection, mode, model class, or required setting is missing or invalid |
| `Proxmox\Exceptions\UnexpectedResponseException` | Proxmox returned data in an unexpected shape |
| `Proxmox\Exceptions\TaskFailedException` | A waited task finished with an error; exposes `upid` and `exitStatus` |
| `Proxmox\Exceptions\TaskTimeoutException` | Task polling exceeded its timeout; exposes `upid` and `timeout` |

All four package exception classes implement `Proxmox\Exceptions\ProxmoxException`. Laravel HTTP exceptions and PHP argument exceptions remain separate. A task timeout stops local polling and does not cancel the task on Proxmox.

## Testing

The package uses Laravel's HTTP client, so application tests can fake API responses without a Proxmox server. Supply dummy connection settings and prevent real requests:

```php
use Illuminate\Support\Facades\Http;
use Proxmox\Facades\Proxmox;

it('lists virtual machines on a node', function () {
    config()->set([
        'proxmox.mode' => 'api',
        'proxmox.default' => 'default',
        'proxmox.connections.default.url' => 'https://proxmox.example.test:8006/api2/json',
        'proxmox.connections.default.api_token_id' => 'laravel@pve!test',
        'proxmox.connections.default.api_token_secret' => 'test-secret',
    ]);

    Proxmox::purge('default');
    Http::preventStrayRequests();
    Http::fake([
        '*/nodes/pve1/qemu' => Http::response([
            'data' => [['vmid' => 101]],
        ]),
    ]);

    expect(Proxmox::node('pve1')->virtualMachines()->all())
        ->toBe([['vmid' => 101]]);

    Http::assertSentCount(1);
});
```

When testing task waits, fake the task status endpoint too, including `status: stopped` and `exitstatus: OK` for a successful result.

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

See [CHANGELOG.md](CHANGELOG.md) for release changes and breaking changes. Earlier API shapes map to the current API as follows:

| Previous API | Current API |
| --- | --- |
| `Proxmox::getNodes()` | `Proxmox::nodes()` |
| `Proxmox::getNode($node)` | `Proxmox::node($node)->status()` |
| `Proxmox::getVirtualMachines($node)` | `Proxmox::node($node)->virtualMachines()->all()` |
| `Proxmox::getVirtualMachine($node, $id)` | `Proxmox::node($node)->virtualMachine($id)->toArray()` |
| `Proxmox::getImages($node, $storage)` | `Proxmox::node($node)->images($storage)` |
| `Proxmox::createVirtualMachine($node, $config)` | `Proxmox::node($node)->virtualMachines()->create($config)` |
| `Proxmox::nodes($node)` | `Proxmox::node($node)` |
| `Proxmox::virtualMachines()` | Select a node, then call `virtualMachines()->all()` |
| `node()->makeVirtualMachine($id)->...->create()` | `node()->virtualMachines()->create(['vmid' => $id, ...])` |
| Inventory VM management methods | `$record->client()` or `$record->resource()` followed by a resource operation |
| `VirtualMachineResource::make($record)` | `$record->client()` or `$record->resource()` |

Listing methods return arrays; use Laravel collections for local queries. Node and VM `get()`/`first()` aliases, the VM `vm()` alias, and the pending creation builder have been removed. Creation and mutation methods return task objects rather than UPID strings.

Inventory models no longer provision or destroy VMs through lifecycle hooks. Replace `creationTask()`, `api()`, and `withoutProxmox()` workflows with explicit [inventory operations](#provisioning-and-recording-a-vm).

Connections now live under `proxmox.default` and `proxmox.connections`. Direct resource imports use `Proxmox\Resources\Nodes`, `Proxmox\Resources\VirtualMachines`, and `Proxmox\Resources\Tasks`; Eloquent inventory models remain under `Proxmox\Models`.

## Contributing

See the [contribution guide](.github/CONTRIBUTING.md) for the development workflow and run `composer test` before submitting changes.

Report vulnerabilities according to the [security policy](.github/SECURITY.md).

## Credits

- [Nima Asaadi](https://github.com/aboutnima)
- [Contributors](https://github.com/aboutnima/proxmox/graphs/contributors)

## License

Released under the [MIT license](LICENSE.md).
