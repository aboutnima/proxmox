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

- Use the package facade to make authenticated requests to Proxmox API endpoints.

## Workflow

### 1. Install and configure

- Install with `composer require aboutnima/proxmox`.
- Optionally publish configuration with `php artisan vendor:publish --tag=proxmox-config`.
- Set `PROXMOX_URL` to the Proxmox API base URL, including `/api2/json`.
- Set `PROXMOX_API_TOKEN_ID` and `PROXMOX_API_TOKEN_SECRET` for API-token authentication.

### 2. Call API endpoints

- Import `Proxmox\Proxmox\Facades\Proxmox`.
- Use `get()` for query parameters and `post()`, `put()`, or `delete()` for form-encoded data.
- Use `request()` for other HTTP methods or custom Laravel HTTP client options.
- Each method returns an `Illuminate\Http\Client\Response`; call `throw()` when non-success responses should raise an exception.

## References

- `README.md` for install and usage examples.
- `config/proxmox.php` for connection, timeout, and TLS verification settings.
- `src/Proxmox.php` and `src/Facades/Proxmox.php` for the public client API.

## Examples

```php
use Proxmox\Proxmox\Facades\Proxmox;

$nodes = Proxmox::get('nodes')
    ->throw()
    ->json('data');

Proxmox::post('nodes/pve1/qemu', [
    'vmid' => 101,
    'name' => 'web',
])->throw();
```

## Anti-patterns

- Do not pass an absolute URL as an endpoint; the client only accepts relative API paths.
- Do not disable TLS verification to work around certificate problems; configure a trusted Proxmox certificate instead.
- Do not assume unsuccessful HTTP responses throw automatically; call `throw()` or inspect the response status.
