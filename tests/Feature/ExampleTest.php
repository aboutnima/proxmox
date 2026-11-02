<?php

declare(strict_types=1);

use Proxmox\Proxmox;
use Proxmox\ProxmoxServiceProvider;

it('resolves the singleton', function () {
    expect(app(Proxmox::class))->toBeInstanceOf(Proxmox::class);
});

it('returns the same instance from the container', function () {
    expect(app(Proxmox::class))->toBe(app(Proxmox::class));
});

it('exposes the provider and facade under a single package namespace', function () {
    expect(class_exists(ProxmoxServiceProvider::class))->toBeTrue()
        ->and(\Proxmox\Facades\Proxmox::getFacadeRoot())->toBe(app(Proxmox::class));
});

it('merges the package config', function () {
    expect(config('proxmox.url'))->toBe('')
        ->and(config('proxmox.api_token_id'))->toBe('')
        ->and(config('proxmox.api_token_secret'))->toBe('')
        ->and(config('proxmox.timeout'))->toBe(30)
        ->and(config('proxmox.verify_ssl'))->toBeTrue();
});
