# Release Notes

## [Unreleased](https://github.com/aboutnima/proxmox/compare/v0.1.0...1.x)

### Added

- `Proxmox\Facades\Cluster` for named API connections, such as `Cluster::connection('hetzner')->nodes()`.
- A single cluster connection registry with encrypted token secrets, per-connection HTTP/TLS settings, and client invalidation after model changes.
- `php artisan proxmox:install [--migrate]` for repeatable configuration and migration publication, and `php artisan proxmox:create-cluster` for interactive connection setup.

### Changed

- **Breaking:** stored clusters are the standard connection source. Removed the `api` / `inventory` modes, configuration-backed connection lists, and inventory model configuration. `config/proxmox.php` now contains the default connection name only.
- **Breaking:** replace the `Proxmox` facade import with `Proxmox\Facades\Cluster`.
- **Breaking:** `node($name)->virtualMachines()` fetches a plain array immediately. Replace `virtualMachines()->all()` with `virtualMachines()` and `virtualMachines()->create($configuration)` with `node($name)->createVirtualMachine($configuration)`.
- Node and VM persistence, customer ownership, caching, and reconciliation are application responsibilities. Package migrations create only `proxmox_clusters`; existing published migrations and data are left untouched.
- Live node/VM resources are independent of Eloquent. VM refresh and serialization use an in-memory status snapshot; remote management methods do not read or write application records.
- Existing live API functionality remains available: native/readable VM creation, cloud-image imports, power operations, configuration changes, metrics, history, snapshots, and background tasks.

### Removed

- Eloquent node and VM models, `HasVirtualMachines`, inventory relationships, resource factories, and deprecated inventory resource aliases.
- Node synchronization commands and lifecycle tracking through package tables.
- Cluster `is_active`, installer mode options, and the cluster command's configuration import options.

## [v0.1.0](https://github.com/aboutnima/proxmox/compare/...v0.1.0) - 202x-xx-xx

Initial pre-release.
