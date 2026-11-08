<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\ProxmoxException;
use Proxmox\Exceptions\TaskFailedException;
use Proxmox\Exceptions\TaskTimeoutException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Facades\ProxmoxCluster;
use Proxmox\Models\Cluster;
use Proxmox\Proxmox as ProxmoxClient;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    $this->cluster = Cluster::query()->create([
        'name' => 'default',
        'url' => 'https://proxmox.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pam!package',
        'api_token_secret' => 'token-secret',
    ]);

    Cluster::query()->create([
        'name' => 'backup',
        'url' => 'https://backup.example.test:8006/api2/json/',
        'api_token_id' => 'backup@pve!package',
        'api_token_secret' => 'backup-secret',
    ]);

    Http::preventStrayRequests();
});

it('resolves a fresh client for every named connection lookup', function () {
    Http::fake(['*' => Http::response(['data' => [['node' => 'backup1']]])]);

    $backup = ProxmoxCluster::connection('backup');

    expect($backup)->toBeInstanceOf(ProxmoxClient::class)
        ->and($backup->getName())->toBe('backup')
        ->and(ProxmoxCluster::connection('backup'))->not->toBe($backup)
        ->and(ProxmoxCluster::connection())->not->toBe($backup)
        ->and(collect($backup->nodes())->pluck('node')->all())->toBe(['backup1']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://backup.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=backup@pve!package=backup-secret'));
});

it('forwards facade calls to the configured default connection', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    ProxmoxCluster::setDefaultConnection('backup');

    expect(ProxmoxCluster::getDefaultConnection())->toBe('backup')
        ->and(ProxmoxCluster::getName())->toBe('backup')
        ->and(app(ProxmoxClient::class)->getName())->toBe('backup');

    ProxmoxCluster::nodes();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://backup.example.test'));
});

it('reads stored changes made without model events on the next lookup', function () {
    $first = ProxmoxCluster::connection();

    Cluster::query()->whereKey($this->cluster->id)->update([
        'url' => 'https://updated.example.test:8006/api2/json',
    ]);

    $second = ProxmoxCluster::connection();
    Http::fake(['*' => Http::response(['data' => []])]);
    $second->nodes();

    expect($second)->not->toBe($first);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://updated.example.test'));
});

it('fails clearly for clusters that are not registered', function () {
    expect(fn () => ProxmoxCluster::connection('missing'))
        ->toThrow(InvalidConfigurationException::class, 'Proxmox cluster [missing] does not exist');
});

it('requires a positive connect timeout', function () {
    $this->cluster->update(['connect_timeout' => 0]);

    expect(fn () => ProxmoxCluster::get('nodes'))
        ->toThrow(InvalidConfigurationException::class, 'connect_timeout');

    Http::assertNothingSent();
});

it('casts environment strings in connection configuration', function () {
    $client = ProxmoxClient::fromConfig(app(Factory::class), [
        'url' => ' https://proxmox.example.test:8006/api2/json/ ',
        'api_token_id' => ' laravel@pam!package ',
        'api_token_secret' => 'token-secret',
        'timeout' => '15',
        'connect_timeout' => '5',
        'verify_ssl' => 'false',
        'retry' => ['times' => '2', 'sleep' => '250'],
    ]);

    expect($client->__debugInfo())->toMatchArray([
        'baseUrl' => 'https://proxmox.example.test:8006/api2/json',
        'apiTokenId' => 'laravel@pam!package',
        'timeout' => 15,
        'connectTimeout' => 5,
        'verifySsl' => false,
        'retries' => 2,
        'retryDelay' => 250,
    ]);
});

it('verifies TLS with a configured CA file path', function () {
    $this->cluster->update(['ca_bundle' => '/etc/ssl/proxmox-ca.pem']);

    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['data' => []]);
    });

    ProxmoxCluster::nodes();

    expect($options['verify'])->toBe('/etc/ssl/proxmox-ca.pem')
        ->and(app(ProxmoxClient::class)->__debugInfo()['caBundle'])->toBe('/etc/ssl/proxmox-ca.pem');
});

it('verifies TLS with an inline PEM certificate', function () {
    $pem = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";
    $this->cluster->update(['ca_bundle' => $pem]);

    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['data' => []]);
    });

    ProxmoxCluster::nodes();

    expect($options['verify'])->toBeTrue()
        ->and($options['curl'][CURLOPT_CAINFO_BLOB])->toBe($pem)
        ->and(print_r(app(ProxmoxClient::class), true))->not->toContain('MIIB')->toContain('[PEM certificate]');
});

it('ignores the CA bundle when TLS verification is disabled', function () {
    $this->cluster->update(['verify_ssl' => false]);
    $this->cluster->update(['ca_bundle' => '/etc/ssl/proxmox-ca.pem']);

    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['data' => []]);
    });

    ProxmoxCluster::nodes();

    expect($options['verify'])->toBeFalse()
        ->and($options)->not->toHaveKey('curl');
});

it('builds standalone runtime connections without registering them by name', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $client = ProxmoxCluster::connectUsing('runtime', [
        'url' => 'https://runtime.example.test:8006/api2/json',
        'api_token_id' => 'runtime@pve!app',
        'api_token_secret' => 'runtime-secret',
    ]);

    expect($client->getName())->toBe('runtime')
        ->and(fn () => ProxmoxCluster::connection('runtime'))->toThrow(InvalidConfigurationException::class, 'does not exist');

    $client->nodes();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://runtime.example.test'));
});

it('keeps runtime settings separate from a stored cluster with the same name', function () {
    $temporary = ProxmoxCluster::connectUsing('default', [
        'url' => 'https://runtime.example.test:8006/api2/json',
        'api_token_id' => 'runtime@pve!app',
        'api_token_secret' => 'runtime-secret',
    ]);
    $stored = ProxmoxCluster::connection('default');

    Http::fake(['*' => Http::response(['data' => []])]);
    $temporary->nodes();
    $stored->nodes();

    Http::assertSentInOrder([
        fn (Request $request): bool => str_starts_with($request->url(), 'https://runtime.example.test'),
        fn (Request $request): bool => str_starts_with($request->url(), 'https://proxmox.example.test'),
    ]);
    expect(Cluster::query()->count())->toBe(2);
});

it('keeps the API token secret out of debug output', function () {
    $output = print_r(app(ProxmoxClient::class), true);

    expect($output)->not->toContain('token-secret')
        ->and($output)->toContain('laravel@pam!package');
});

it('retries GET requests after connection failures when configured', function () {
    $this->cluster->update(['retry_times' => 2, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->pushFailedConnection()
        ->push(['data' => [['node' => 'pve1']]]),
    ]);

    expect(collect(ProxmoxCluster::nodes())->pluck('node')->all())->toBe(['pve1']);
});

it('gives up after the configured number of GET retries', function () {
    $this->cluster->update(['retry_times' => 1, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->pushFailedConnection()
        ->push(['data' => []]),
    ]);

    expect(fn () => ProxmoxCluster::nodes())->toThrow(ConnectionException::class);
});

it('applies read retry policy to generic requests regardless of method case', function () {
    $this->cluster->update(['retry_times' => 1, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => 'ok']),
    ]);

    expect(ProxmoxCluster::request('get', 'version')->json('data'))->toBe('ok');
});

it('does not retry generic write requests', function () {
    $this->cluster->update(['retry_times' => 1, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => 'unexpected retry']),
    ]);

    expect(fn () => ProxmoxCluster::request('post', 'nodes/pve1/qemu', ['form_params' => ['vmid' => 101]]))
        ->toThrow(ConnectionException::class);
});

it('does not retry HTTP error responses', function () {
    $this->cluster->update(['retry_times' => 2, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->push(['errors' => ['node' => 'missing']], 500)
        ->push(['data' => []]),
    ]);

    expect(ProxmoxCluster::get('nodes')->status())->toBe(500);

    Http::assertSentCount(1);
});

it('never retries write requests', function (string $method) {
    $this->cluster->update(['retry_times' => 2, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => 'UPID:retried']),
    ]);

    expect(fn () => ProxmoxCluster::{$method}('nodes/pve1/qemu/101/status/start'))->toThrow(ConnectionException::class);
})->with(['post', 'put', 'delete']);

it('adds connection details without secrets to the about command', function () {
    $this->artisan('about', ['--only' => 'proxmox'])
        ->expectsOutputToContain('Default connection')
        ->expectsOutputToContain('backup, default')
        ->doesntExpectOutputToContain('token-secret')
        ->assertSuccessful();
});

it('marks every package exception with a common interface', function (Throwable $exception) {
    expect($exception)->toBeInstanceOf(ProxmoxException::class);
})->with([
    'configuration' => fn () => InvalidConfigurationException::missingUrl('default'),
    'response' => fn () => UnexpectedResponseException::expectedList(),
    'task failed' => fn () => new TaskFailedException('UPID:x', 'ERROR'),
    'task timeout' => fn () => new TaskTimeoutException('UPID:x', 1),
]);
