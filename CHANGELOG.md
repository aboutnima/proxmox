# Release Notes

## [Unreleased](https://github.com/aboutnima/proxmox/compare/v0.1.0...1.x)

### Added

- VM `usage()`, `resources()`, and `availableResources()`, plus node `resources()` and `storages($content)`, return categorized CPU, memory (MiB), and disk (GiB) data for live usage, the applied allocation, and room to grow.
- Package modes selected by `php artisan proxmox:install`: `api` (connections from `.env`, no database) and `inventory` (clusters, nodes, and VMs stored in the database). Set with `PROXMOX_MODE`.
- Inventory migration `create_proxmox_tables` (`proxmox_clusters`, `proxmox_nodes`, `proxmox_virtual_machines`) published by the installer or the `proxmox-migrations` tag. The installer never publishes it twice.
- `Proxmox\Models\Cluster`, `Node`, and `VirtualMachine` Eloquent models, overridable through `proxmox.models`. Cluster API token secrets are encrypted and hidden.
- `php artisan proxmox:create-cluster` to add clusters interactively or move `.env` connections with `--from-env`.
- `php artisan proxmox:sync-nodes` and `Cluster::syncNodes()` to add and update inventory nodes from Proxmox. Prompts for clusters, or accepts names or `--all` for scheduling. Nodes no longer listed are kept with the `missing` status.
- `Proxmox\Concerns\HasVirtualMachines` trait for models that own inventory VMs through a polymorphic `owner` relation.
- `Proxmox\Resources\VirtualMachines\VirtualMachine::make($record)` selects a saved inventory VM's cluster, node, and VMID without fetching or mutating the record.
- `delete($purge = true)` on the VM resource destroys a VM and its unreferenced disks.
- In inventory mode, `Proxmox::connection($name)` resolves database clusters, and cached clients are rebuilt when a cluster changes.
- `Proxmox::connectUsing($name, $config)` for runtime connections.
- `ca_bundle` connection option and `PROXMOX_CA_BUNDLE`, accepting a CA file path or PEM certificate.
- Multiple connections through `Proxmox::connection('name')`, backed by `Proxmox\ProxmoxManager`.
- `Task` resource with `status()`, `isRunning()`, `wait()`, and `log()`, plus `Proxmox::task($upid)` and `node()->task($upid)`.
- Node-scoped VM operations with `virtualMachines()->all()` for array listings and `virtualMachines()->create($configuration)` for creation, with documented array keys for IDE completion.
- `Proxmox::nextVirtualMachineId()` for requesting a free VM ID.
- `virtualMachine($vmid)->config()`, plus `setCpu($cores, $sockets)`, `setCores($cores)`, `setMemory($megabytes)`, and `growDisk($disk, $gigabytes)`. Each change returns a `Task`.
- VM power operations: `start()`, `shutdown()`, `restart()`, `pause()`, `hibernate()`, `resume()`, `stop()`, and `reset()`. Each returns a `Task`.
- Fetched VM snapshots with `toArray()`, JSON serialization, and `refresh()`. VM objects retain `status()`, `config()`, `setCpu()`, `setMemory()`, and `growDisk()` for management.
- `PROXMOX_CONNECT_TIMEOUT`, `PROXMOX_RETRY_TIMES`, and `PROXMOX_RETRY_SLEEP` settings. Only GET requests retry, and only after connection failures.
- Package exceptions implementing `Proxmox\Exceptions\ProxmoxException`.
- Connection details and the package mode in `php artisan about`.

### Changed

- **Breaking:** inventory `VirtualMachine` Eloquent operations now change database records only. Removed automatic provisioning/destruction, model management methods, `resource()`, `api()`, `creationTask()`, and `withoutProxmox()`. Use the VM resource's `make($record)` for live operations and provision/delete inventory explicitly. VMID collisions and missing remote VMs are no longer retried or silently accepted.
- **Breaking:** removed `ClusterVirtualMachines` and `Proxmox::virtualMachines()`. List VMs with `Proxmox::node($node)->virtualMachines()->all()`.
- **Breaking:** resource classes use the domain namespaces `Proxmox\Resources\Nodes`, `Proxmox\Resources\VirtualMachines`, and `Proxmox\Resources\Tasks`. Update direct class imports.
- **Breaking:** removed `ResourceQuery` and the `Nodes` / `Images` listing classes. `VirtualMachines` now provides only node-scoped `all()` and `create()` operations. `nodes()`, `virtualMachines()->all()`, `node()->images($storage)`, and `task()->log()` return arrays. Use `collect($data)` for local filtering and aggregation.
- **Breaking:** removed `PendingVirtualMachine` and node `makeVirtualMachine()` / `createVirtualMachine()` methods. Pass a configuration array with a valid `vmid` to `node()->virtualMachines()->create()`.
- **Breaking:** removed facade shortcuts `getNodes()`, `getNode()`, `getVirtualMachines()`, `getVirtualMachine()`, `getImages()`, and `createVirtualMachine()`. Use the node-scoped API; creation is `node()->virtualMachines()->create($configuration)`.
- **Breaking:** `nodes($name)` is no longer an alias; select a node with `node($name)`. `virtualMachine($vmid)` requires an ID and fetches runtime data immediately. Node and VM `get()` / `first()` aliases are removed; use node `status()` or VM `toArray()` / `status()` as appropriate.
- VM configuration lives beside the VM classes in `Proxmox\Resources\VirtualMachines\VirtualMachineConfiguration`. Shared exceptions remain in `Proxmox\Exceptions`.
- **Breaking:** configuration moved to `proxmox.default` and `proxmox.connections.{name}`. Re-publish `config/proxmox.php`.
- **Breaking:** VM creation returns a `Proxmox\Resources\Tasks\Task` instead of a UPID string. Use `$task->upid`, or cast the task to a string.
- **Breaking:** the facade now resolves `Proxmox\ProxmoxManager`, and the `Proxmox\Proxmox` constructor takes the HTTP client factory first. Prefer `Proxmox::connection()` or `Proxmox::fromConfig()`.
- **Breaking:** VM IDs must be between 100 and 999999999, and node names and VM IDs are validated as soon as they are selected.
- Task polling treats `WARNINGS: n` exit statuses as successful, as Proxmox does.
- Configuration and response errors throw package exceptions, which still extend `LogicException` and `UnexpectedValueException`.
- **Breaking:** removed the `vm($vmid)` alias; use `virtualMachine($vmid)` to select a VM.

### Fixed

- Resource response keys now have consistent alphabetical ordering at every nesting level, preserving list item order and field values.
- Simplified the VM creation array-shape PHPDoc for PhpStorm compatibility while retaining typed keys and native parameter passthrough.
- `delete()` sends parameters as query parameters, because Proxmox ignores DELETE request bodies.
- The API token secret is hidden from `var_dump()`, `print_r()`, and stack traces.


## [v0.1.0](https://github.com/aboutnima/proxmox/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
