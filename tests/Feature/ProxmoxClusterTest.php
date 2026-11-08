<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Facades\ProxmoxCluster;
use Proxmox\Models\Cluster;
use Proxmox\Proxmox;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    $this->cluster = Cluster::query()->create([
        'name' => 'default',
        'url' => 'https://eu.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'cluster-secret',
    ]);

    Http::preventStrayRequests();
});

it('encrypts stored API token secrets and hides them from serialization', function () {
    $stored = DB::table('clusters')->value('api_token_secret');

    expect($stored)->not->toBe('cluster-secret')
        ->and(decrypt($stored, false))->toBe('cluster-secret')
        ->and($this->cluster->fresh()->api_token_secret)->toBe('cluster-secret')
        ->and($this->cluster->toArray())->not->toHaveKey('api_token_secret')
        ->and($this->cluster->toJson())->not->toContain('cluster-secret');
});

it('applies connection defaults and preserves per-cluster TLS settings', function () {
    $this->cluster->update(['ca_bundle' => '/etc/pve/pve-root-ca.pem']);

    expect($this->cluster->fresh()->toConnectionConfig())->toBe([
        'url' => 'https://eu.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'cluster-secret',
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify_ssl' => true,
        'ca_bundle' => '/etc/pve/pve-root-ca.pem',
        'retry' => ['times' => 0, 'sleep' => 100],
    ]);
});

it('builds an API client directly from a cluster record without another lookup', function () {
    $cluster = Cluster::findOrFail($this->cluster->id);
    Http::fake(['*' => Http::response(['data' => [['node' => 'pve1']]])]);
    DB::enableQueryLog();

    $client = $cluster->client();

    expect($client)->toBeInstanceOf(Proxmox::class)
        ->and($client->getName())->toBe('default')
        ->and(DB::getQueryLog())->toBe([]);

    DB::disableQueryLog();
    Http::assertNothingSent();

    expect($client->nodes())->toBe([['node' => 'pve1']]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eu.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pve!app=cluster-secret'));
    Http::assertSentCount(1);
});

it('builds fresh model clients using current attributes without saving them', function () {
    $cluster = Cluster::findOrFail($this->cluster->id);
    $first = $cluster->client();
    $cluster->fill([
        'name' => 'temporary',
        'url' => 'https://temporary.example.test:8006/api2/json',
        'api_token_secret' => 'temporary-secret',
    ]);
    $second = $cluster->client();

    expect($second !== $first)->toBeTrue()
        ->and($first->getName())->toBe('default')
        ->and($second->getName())->toBe('temporary')
        ->and(ProxmoxCluster::getDefaultConnection())->toBe('default')
        ->and($cluster->fresh()->api_token_secret)->toBe('cluster-secret');

    Http::fake(['*' => Http::response(['data' => []])]);
    $first->nodes();
    $second->nodes();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://eu.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pve!app=cluster-secret'));
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://temporary.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pve!app=temporary-secret'));
    Http::assertSentCount(2);
});

it('uses updated credentials when resolving the next connection', function () {
    $first = ProxmoxCluster::connection();
    $this->cluster->update(['api_token_secret' => 'rotated-secret']);
    $second = ProxmoxCluster::connection();

    Http::fake(['*' => Http::response(['data' => []])]);
    $second->nodes();

    expect($second)->not->toBe($first);
    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pve!app=rotated-secret'));
});

it('resolves renamed clusters without model event invalidation', function () {
    $first = ProxmoxCluster::connection();
    Cluster::query()->whereKey($this->cluster->id)->update(['name' => 'hetzner']);

    expect(ProxmoxCluster::connection('hetzner'))->not->toBe($first)
        ->and(fn () => ProxmoxCluster::connection('default'))->toThrow(InvalidConfigurationException::class);
});

it('rejects a deleted cluster even when deletion bypasses model events', function () {
    ProxmoxCluster::connection();
    Cluster::query()->whereKey($this->cluster->id)->delete();

    expect(fn () => ProxmoxCluster::connection())->toThrow(InvalidConfigurationException::class, 'does not exist');
    Http::assertNothingSent();
});

it('saves and deletes connection settings without calling Proxmox', function () {
    $this->cluster->update(['url' => 'https://changed.example.test:8006/api2/json']);
    $this->cluster->delete();

    Http::assertNothingSent();
});
