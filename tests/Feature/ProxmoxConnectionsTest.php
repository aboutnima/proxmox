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
use Proxmox\Facades\Cluster;
use Proxmox\Models\Cluster as ClusterRecord;
use Proxmox\Proxmox as ProxmoxClient;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    $this->cluster = ClusterRecord::query()->create([
        'name' => 'default',
        'url' => 'https://proxmox.example.test:8006/api2/json',
        'api_token_id' => 'laravel@pam!package',
        'api_token_secret' => 'token-secret',
    ]);

    ClusterRecord::query()->create([
        'name' => 'backup',
        'url' => 'https://backup.example.test:8006/api2/json/',
        'api_token_id' => 'backup@pve!package',
        'api_token_secret' => 'backup-secret',
    ]);

    Http::preventStrayRequests();
});

it('resolves a fresh client for every named connection lookup', function () {
    Http::fake(['*' => Http::response(['data' => [['node' => 'backup1']]])]);

    $backup = Cluster::connection('backup');

    expect($backup)->toBeInstanceOf(ProxmoxClient::class)
        ->and($backup->getName())->toBe('backup')
        ->and(Cluster::connection('backup'))->not->toBe($backup)
        ->and(Cluster::connection())->not->toBe($backup)
        ->and(collect($backup->nodes())->pluck('node')->all())->toBe(['backup1']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://backup.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=backup@pve!package=backup-secret'));
});

it('forwards facade calls to the configured default connection', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    Cluster::setDefaultConnection('backup');

    expect(Cluster::getDefaultConnection())->toBe('backup')
        ->and(Cluster::getName())->toBe('backup')
        ->and(app(ProxmoxClient::class)->getName())->toBe('backup');

    Cluster::nodes();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://backup.example.test'));
});

it('reads stored changes made without model events on the next lookup', function () {
    $first = Cluster::connection();

    ClusterRecord::query()->whereKey($this->cluster->id)->update([
        'url' => 'https://updated.example.test:8006/api2/json',
    ]);

    $second = Cluster::connection();
    Http::fake(['*' => Http::response(['data' => []])]);
    $second->nodes();

    expect($second)->not->toBe($first);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://updated.example.test'));
});

it('fails clearly for clusters that are not registered', function () {
    expect(fn () => Cluster::connection('missing'))
        ->toThrow(InvalidConfigurationException::class, 'Proxmox cluster [missing] does not exist');
});

it('requires a positive connect timeout', function () {
    $this->cluster->update(['connect_timeout' => 0]);

    expect(fn () => Cluster::get('nodes'))
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

    Cluster::nodes();

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

    Cluster::nodes();

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

    Cluster::nodes();

    expect($options['verify'])->toBeFalse()
        ->and($options)->not->toHaveKey('curl');
});

it('builds standalone runtime connections without registering them by name', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $client = Cluster::connectUsing('runtime', [
        'url' => 'https://runtime.example.test:8006/api2/json',
        'api_token_id' => 'runtime@pve!app',
        'api_token_secret' => 'runtime-secret',
    ]);

    expect($client->getName())->toBe('runtime')
        ->and(fn () => Cluster::connection('runtime'))->toThrow(InvalidConfigurationException::class, 'does not exist');

    $client->nodes();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://runtime.example.test'));
});

it('keeps runtime settings separate from a stored cluster with the same name', function () {
    $temporary = Cluster::connectUsing('default', [
        'url' => 'https://runtime.example.test:8006/api2/json',
        'api_token_id' => 'runtime@pve!app',
        'api_token_secret' => 'runtime-secret',
    ]);
    $stored = Cluster::connection('default');

    Http::fake(['*' => Http::response(['data' => []])]);
    $temporary->nodes();
    $stored->nodes();

    Http::assertSentInOrder([
        fn (Request $request): bool => str_starts_with($request->url(), 'https://runtime.example.test'),
        fn (Request $request): bool => str_starts_with($request->url(), 'https://proxmox.example.test'),
    ]);
    expect(ClusterRecord::query()->count())->toBe(2);
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

    expect(collect(Cluster::nodes())->pluck('node')->all())->toBe(['pve1']);
});

it('gives up after the configured number of GET retries', function () {
    $this->cluster->update(['retry_times' => 1, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->pushFailedConnection()
        ->push(['data' => []]),
    ]);

    expect(fn () => Cluster::nodes())->toThrow(ConnectionException::class);
});

it('applies read retry policy to generic requests regardless of method case', function () {
    $this->cluster->update(['retry_times' => 1, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => 'ok']),
    ]);

    expect(Cluster::request('get', 'version')->json('data'))->toBe('ok');
});

it('does not retry generic write requests', function () {
    $this->cluster->update(['retry_times' => 1, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => 'unexpected retry']),
    ]);

    expect(fn () => Cluster::request('post', 'nodes/pve1/qemu', ['form_params' => ['vmid' => 101]]))
        ->toThrow(ConnectionException::class);
});

it('does not retry HTTP error responses', function () {
    $this->cluster->update(['retry_times' => 2, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->push(['errors' => ['node' => 'missing']], 500)
        ->push(['data' => []]),
    ]);

    expect(Cluster::get('nodes')->status())->toBe(500);

    Http::assertSentCount(1);
});

it('never retries write requests', function (string $method) {
    $this->cluster->update(['retry_times' => 2, 'retry_sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => 'UPID:retried']),
    ]);

    expect(fn () => Cluster::{$method}('nodes/pve1/qemu/101/status/start'))->toThrow(ConnectionException::class);
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
