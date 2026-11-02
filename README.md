<div align="center">
    <h1>Proxmox</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/aboutnima/proxmox"><img src="https://img.shields.io/packagist/v/aboutnima/proxmox.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/aboutnima/proxmox"><img src="https://img.shields.io/packagist/php-v/aboutnima/proxmox.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/aboutnima/proxmox"><img src="https://badge.laravel.cloud/badge/aboutnima/proxmox?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/aboutnima/proxmox/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/aboutnima/proxmox/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/aboutnima/proxmox"><img src="https://img.shields.io/packagist/dt/aboutnima/proxmox.svg?style=flat-square" alt="Total Downloads"></a>
</p>

A Laravel package for integrating with the Proxmox REST API.

## Installation

You can install the package via Composer:

```bash
composer require aboutnima/proxmox
```

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="proxmox-config"
```

## Usage

Configure an API token in your application's `.env` file. `PROXMOX_URL` should include the `/api2/json` path:

```dotenv
PROXMOX_URL=https://proxmox.example.com:8006/api2/json
PROXMOX_API_TOKEN_ID=laravel@pam!app
PROXMOX_API_TOKEN_SECRET=your-token-secret
```

Use the facade to call any Proxmox API endpoint. GET parameters are sent as query parameters, and write parameters are sent as form data:

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

The client also provides `put()`, `delete()`, and `request()` methods. Each returns a Laravel HTTP `Response`; call `throw()` when you want unsuccessful responses to raise an exception. TLS verification is enabled by default and can be configured with `PROXMOX_VERIFY_SSL`; request timeout defaults to 30 seconds and can be changed with `PROXMOX_TIMEOUT`.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Proxmox! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Nima Asaadi](https://github.com/aboutnima)
- [All Contributors](../../contributors)

## License

Proxmox is open-sourced software licensed under the [MIT license](LICENSE.md).
