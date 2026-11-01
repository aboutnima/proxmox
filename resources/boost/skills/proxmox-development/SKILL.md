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

- Use only the package's implemented public API; do not assume REST client methods or configuration that are not present.

## Workflow

### 1. Check the package capabilities

- Review the package README and implementation before writing integration code.
- The current `Proxmox` class and `proxmox` config are placeholders; the package does not yet expose REST API client methods or connection settings.

### 2. Install and inspect available resources

- Install with `composer require aboutnima/proxmox`.
- Publish package resources only when needed, using the tags documented in the README.
- Do not write application code against imagined package methods. Use an existing supported API only after verifying it in the package.

## Rules, References, and Templates

Read before making integration changes:

- `README.md`
- `config/proxmox.php`
- `src/Proxmox.php`

## Examples

- Do not provide REST API usage examples until the package exposes a client API and configuration for them.

## Anti-patterns

- Do not treat placeholder config, migrations, or commands as Proxmox API functionality.
- Do not invent endpoints, authentication options, or facade methods.
