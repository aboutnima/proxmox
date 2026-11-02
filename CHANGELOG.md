# Release Notes

## [Unreleased](https://github.com/aboutnima/proxmox/compare/v0.1.0...1.x)

### Added

- Multiple connections through `Proxmox::connection('name')`, backed by `Proxmox\ProxmoxManager`.
- `Task` resource with `status()`, `isRunning()`, `wait()`, and `log()`, plus `Proxmox::task($upid)` and `node()->task($upid)`.
- Fluent VM builder: `node()->virtualMachines()->make($vmid)->name()->cores()->...->create()`.
- `Proxmox::nextVirtualMachineId()` for requesting a free VM ID.
- `virtualMachine($vmid)->config()` and `virtualMachine($vmid)->resize($disk, $size)`.
- `whereIn()`, `whereNotIn()`, `when()`/`unless()`, macros, and read-only collection passthrough (`count()`, `pluck()`, `sum()`, ...) on listings.
- `PROXMOX_CONNECT_TIMEOUT`, `PROXMOX_RETRY_TIMES`, and `PROXMOX_RETRY_SLEEP` settings. Only GET requests retry, and only after connection failures.
- Package exceptions implementing `Proxmox\Exceptions\ProxmoxException`.
- Connection details in `php artisan about`.

### Changed

- **Breaking:** removed `ClusterVirtualMachines` and `Proxmox::virtualMachines()`. List VMs with `Proxmox::node($node)->virtualMachines()`.
- **Breaking:** resource classes now use the domain namespaces `Proxmox\Nodes`, `Proxmox\VirtualMachines`, `Proxmox\Images`, and `Proxmox\Tasks`. Update direct class imports; facade calls for node-scoped resources keep the same API.
- **Breaking:** listing macros now import `Proxmox\Support\ResourceQuery` instead of `Proxmox\Resources\ResourceQuery`.
- VM configuration lives beside the VM classes in `Proxmox\VirtualMachines\VirtualMachineConfiguration`. Shared exceptions remain in `Proxmox\Exceptions`.
- **Breaking:** configuration moved to `proxmox.default` and `proxmox.connections.{name}`. Re-publish `config/proxmox.php`.
- **Breaking:** VM creation returns a `Proxmox\Tasks\Task` instead of a UPID string. Use `$task->upid`, or cast the task to a string.
- **Breaking:** the facade now resolves `Proxmox\ProxmoxManager`, and the `Proxmox\Proxmox` constructor takes the HTTP client factory first. Prefer `Proxmox::connection()` or `Proxmox::fromConfig()`.
- **Breaking:** VM IDs must be between 100 and 999999999, and node names and VM IDs are validated as soon as they are selected.
- Task polling treats `WARNINGS: n` exit statuses as successful, as Proxmox does.
- Configuration and response errors throw package exceptions, which still extend `LogicException` and `UnexpectedValueException`.
- **Breaking:** removed the `vm($vmid)` alias; use `virtualMachine($vmid)` to select a VM.

### Fixed

- `delete()` sends parameters as query parameters, because Proxmox ignores DELETE request bodies.
- The API token secret is hidden from `var_dump()`, `print_r()`, and stack traces.


## [v0.1.0](https://github.com/aboutnima/proxmox/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
