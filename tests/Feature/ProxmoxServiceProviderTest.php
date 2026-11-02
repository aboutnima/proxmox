<?php

declare(strict_types=1);

use Proxmox\Proxmox;
use Proxmox\ProxmoxManager;
use Proxmox\ProxmoxServiceProvider;

it('resolves the default connection', function () {
    expect(app(Proxmox::class))->toBeInstanceOf(Proxmox::class)
        ->and(app(Proxmox::class)->getName())->toBe('default');
});

it('returns the same instance from the container', function () {
    expect(app(Proxmox::class))->toBe(app(Proxmox::class))
        ->and(app(ProxmoxManager::class))->toBe(app(ProxmoxManager::class));
});

it('exposes the provider and facade under a single package namespace', function () {
    expect(class_exists(ProxmoxServiceProvider::class))->toBeTrue()
        ->and(\Proxmox\Facades\Proxmox::getFacadeRoot())->toBe(app(ProxmoxManager::class))
        ->and(\Proxmox\Facades\Proxmox::connection())->toBe(app(Proxmox::class));
});

it('merges the package config', function () {
    expect(config('proxmox.default'))->toBe('default')
        ->and(config('proxmox.connections.default'))->toBe([
            'url' => '',
            'api_token_id' => '',
            'api_token_secret' => '',
            'timeout' => 30,
            'connect_timeout' => 10,
            'verify_ssl' => true,
            'retry' => ['times' => 0, 'sleep' => 100],
        ]);
});
