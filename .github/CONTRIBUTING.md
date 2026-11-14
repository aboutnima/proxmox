# Contribution Guide

Contribute to [jaryan-host/proxmox](https://github.com/jaryan-host/proxmox) by reporting bugs, improving documentation, or submitting a pull request.

For significant changes, [open an issue](https://github.com/jaryan-host/proxmox/issues) first so we can discuss the approach. Include a minimal reproduction, relevant PHP and Laravel versions, and the expected behavior when reporting a bug.

## Local setup

Use a PHP version compatible with the [package requirements](../README.md#requirements), Composer, and the `pdo_sqlite` extension for tests.

Fork the repository on GitHub, then clone your fork and install the development dependencies. Replace `YOUR-USERNAME` with your GitHub username:

```bash
git clone https://github.com/YOUR-USERNAME/proxmox.git
cd proxmox
git switch -c your-change
composer install
```

## Make a change

- Keep changes focused and use Laravel-native package APIs and the existing service provider structure.
- Add focused Pest tests for behavior changes through public APIs, commands, routes, or published resources. Use Laravel HTTP fakes for Proxmox requests.
- Update the README and relevant examples when public behavior changes. Keep `resources/boost/skills/proxmox-development/SKILL.md` aligned with consumer-facing changes.
- Keep the package focused on cluster connections and the live Proxmox API. Applications own node and VM persistence, authorization, and service lifecycles.

Run the formatter when changing PHP files:

```bash
composer lint
```

## Validate your changes

Run the full validation suite before opening a pull request:

```bash
composer test
```

This runs PHPStan, the formatting check, type coverage at a 100% minimum, and the Pest suite. For individual checks, use `composer analyse`, `composer lint:check`, `composer test:types`, or `composer test:unit`.

To try the package in the local Testbench workbench, run `composer build`, or use `composer serve` to build and serve it.

## Submit a pull request

1. Commit your changes and push the branch to your fork.
2. Open a pull request against the `main` branch of [jaryan-host/proxmox](https://github.com/jaryan-host/proxmox/compare).
3. Describe the problem, the resulting behavior, and how you validated the change. Link any related issue and call out breaking changes.
4. Keep the pull request focused and address review feedback.

Report security vulnerabilities privately according to the [security policy](SECURITY.md).
