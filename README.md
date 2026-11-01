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

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="proxmox"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="proxmox-config"
```

### Publishing and Running the Migrations

```bash
php artisan vendor:publish --tag="proxmox-migrations"
php artisan migrate
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="proxmox-assets"
```

## Usage

<!-- Add a basic usage example here. -->

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
