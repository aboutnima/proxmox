# Release Notes

## [Unreleased](https://github.com/aboutnima/proxmox/compare/v0.1.0...1.x)

### Added

- `Proxmox\Facades\ProxmoxCluster` for named API connections, such as `ProxmoxCluster::connection('hetzner')->nodes()`.
- A single cluster connection registry with encrypted token secrets and per-connection HTTP/TLS settings.
- A `client()` method on the `Cluster` model to build a fresh API client directly from a record, including `Cluster::findOrFail(1)->client()->nodes()`, without another database lookup.
- Configurable cluster models through `proxmox.model`, used for connection lookup, cluster creation, duplicate checks, and the `about` listing. Custom models extend `Proxmox\Models\Cluster`.
- `php artisan proxmox:install [--migrate]` for repeatable configuration and migration publication, and `php artisan proxmox:create-cluster` for interactive connection setup.

### Changed

- **Breaking:** connection lookups reload stored settings and build fresh clients every time. Runtime `connectUsing()` clients are standalone and do not register or override named connections.
- **Breaking:** stored clusters are the standard connection source. Removed the `api` / `inventory` modes, configuration-backed connection lists, and inventory model configuration. `config/proxmox.php` contains the default connection name and cluster model class.
- **Breaking:** replace the `Proxmox\Facades\Cluster` or `Proxmox\Facades\Proxmox` facade import with `Proxmox\Facades\ProxmoxCluster`. The auto-discovered alias is now `ProxmoxCluster`; the Eloquent model remains `Proxmox\Models\Cluster`.
- **Breaking:** `node($name)->virtualMachines()` fetches a plain array immediately. Replace `virtualMachines()->all()` with `virtualMachines()` and `virtualMachines()->create($configuration)` with `node($name)->createVirtualMachine($configuration)`.
- Node and VM persistence, customer ownership, caching, and reconciliation are application responsibilities. Package migrations create only `clusters`; existing published migrations and data are left untouched.
- Live node/VM resources are independent of Eloquent. VM refresh and serialization use an in-memory status snapshot; remote management methods do not read or write application records.
- Existing live API functionality remains available: native/readable VM creation, cloud-image imports, power operations, configuration changes, metrics, history, snapshots, and background tasks.

### Removed

- VM `osInfo()` and the guest-agent operating system information helper.
- Connection client caching, `purge()`, `getConnections()`, and model cache-invalidation hooks.
- Eloquent node and VM models, `HasVirtualMachines`, inventory relationships, resource factories, and deprecated inventory resource aliases.
- Node synchronization commands and lifecycle tracking through package tables.
- Cluster `is_active`, installer mode options, and the cluster command's configuration import options.

## [v0.1.0](https://github.com/aboutnima/proxmox/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
