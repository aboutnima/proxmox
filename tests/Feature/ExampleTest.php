<?php

declare(strict_types=1);

use Proxmox\Proxmox\Proxmox;

it('resolves the singleton', function () {
    expect(app(Proxmox::class))->toBeInstanceOf(Proxmox::class);
});

it('returns the same instance from the container', function () {
    expect(app(Proxmox::class))->toBe(app(Proxmox::class));
});

it('merges the package config', function () {
    expect(config('proxmox.placeholder'))->toBe('default');
});

it('registers the artisan command', function () {
    $this->artisan('proxmox:placeholder')
        ->expectsOutputToContain('Proxmox placeholder command executed.')
        ->assertSuccessful();
});
