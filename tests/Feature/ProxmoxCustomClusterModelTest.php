<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Facades\Cluster;
use Proxmox\Tests\Fixtures\CustomCluster;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    Schema::rename('proxmox_clusters', 'custom_proxmox_clusters');
    config()->set('proxmox.model', CustomCluster::class);

    $this->attributes = [
        'name' => 'hetzner',
        'url' => 'https://custom.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pve!custom',
        'api_token_secret' => 'custom-secret',
    ];

    Http::preventStrayRequests();
});

afterEach(function () {
    Schema::dropIfExists('custom_proxmox_clusters');
});

it('resolves connections through the configured cluster model', function () {
    CustomCluster::query()->create($this->attributes);
    Http::fake(['*' => Http::response(['data' => [['node' => 'pve1']]])]);

    expect(Cluster::connection('hetzner')->nodes())->toBe([['node' => 'pve1']]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://custom.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pve!custom=custom-secret'));
});

it('creates and checks duplicate connections through the configured model', function () {
    $this->artisan('proxmox:create-cluster', [
        'name' => 'hetzner',
        '--url' => $this->attributes['url'],
        '--token-id' => $this->attributes['api_token_id'],
    ])
        ->expectsQuestion('API token secret', 'custom-secret')
        ->assertSuccessful();

    expect(CustomCluster::query()->sole()->api_token_secret)->toBe('custom-secret');

    $this->artisan('proxmox:create-cluster', ['name' => 'hetzner'])
        ->expectsOutputToContain('Cluster [hetzner] already exists.')
        ->assertFailed();
});

it('lists configured model connections in the about command', function () {
    CustomCluster::query()->create($this->attributes);

    $this->artisan('about', ['--only' => 'proxmox'])
        ->expectsOutputToContain('hetzner')
        ->doesntExpectOutputToContain('custom-secret')
        ->assertSuccessful();
});

it('rejects invalid cluster model configuration before querying or requesting', function (mixed $model) {
    config()->set('proxmox.model', $model);

    expect(fn () => Cluster::connection('hetzner'))
        ->toThrow(InvalidConfigurationException::class, 'proxmox.model');

    Http::assertNothingSent();
})->with([
    'null' => [null],
    'empty' => [''],
    'missing class' => ['App\Models\MissingCluster'],
    'unrelated class' => [stdClass::class],
    'unrelated Eloquent model' => [Model::class],
    'integer' => [123],
    'array' => [[]],
]);

it('reports invalid model configuration without suggesting missing migrations', function () {
    config()->set('proxmox.model', stdClass::class);

    expect(fn () => Artisan::call('about', ['--only' => 'proxmox']))
        ->toThrow(InvalidConfigurationException::class, 'proxmox.model');
});
