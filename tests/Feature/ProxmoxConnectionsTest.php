<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\ProxmoxException;
use Proxmox\Exceptions\TaskFailedException;
use Proxmox\Exceptions\TaskTimeoutException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Facades\Proxmox;
use Proxmox\Proxmox as ProxmoxClient;

beforeEach(function () {
    config()->set([
        'proxmox.connections.default.url' => 'https://proxmox.example.test:8006/api2/json',
        'proxmox.connections.default.api_token_id' => 'laravel@pam!package',
        'proxmox.connections.default.api_token_secret' => 'token-secret',
        'proxmox.connections.backup' => [
            'url' => 'https://backup.example.test:8006/api2/json/',
            'api_token_id' => 'backup@pve!package',
            'api_token_secret' => 'backup-secret',
        ],
    ]);

    Http::preventStrayRequests();
});

it('resolves and caches named connections', function () {
    Http::fake(['*' => Http::response(['data' => [['node' => 'backup1']]])]);

    $backup = Proxmox::connection('backup');

    expect($backup)->toBeInstanceOf(ProxmoxClient::class)
        ->and($backup->getName())->toBe('backup')
        ->and(Proxmox::connection('backup'))->toBe($backup)
        ->and(Proxmox::connection())->not->toBe($backup)
        ->and(collect($backup->nodes())->pluck('node')->all())->toBe(['backup1']);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://backup.example.test:8006/api2/json/nodes'
        && $request->hasHeader('Authorization', 'PVEAPIToken=backup@pve!package=backup-secret'));
});

it('forwards facade calls to the configured default connection', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    Proxmox::setDefaultConnection('backup');

    expect(Proxmox::getDefaultConnection())->toBe('backup')
        ->and(Proxmox::getName())->toBe('backup')
        ->and(app(ProxmoxClient::class))->toBe(Proxmox::connection('backup'));

    Proxmox::nodes();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://backup.example.test'));
});

it('rebuilds a purged connection from the current configuration', function () {
    $first = Proxmox::connection();

    Proxmox::purge();

    expect(Proxmox::connection())->not->toBe($first)
        ->and(Proxmox::getConnections())->toHaveKey('default');
});

it('fails clearly for connections that are not configured', function () {
    expect(fn () => Proxmox::connection('missing'))
        ->toThrow(InvalidConfigurationException::class, 'Proxmox connection [missing] is not configured');
});

it('requires a positive connect timeout', function () {
    config()->set('proxmox.connections.default.connect_timeout', 0);

    expect(fn () => Proxmox::get('nodes'))
        ->toThrow(InvalidConfigurationException::class, 'PROXMOX_CONNECT_TIMEOUT');

    Http::assertNothingSent();
});

it('casts environment strings in connection configuration', function () {
    config()->set('proxmox.connections.default', [
        'url' => ' https://proxmox.example.test:8006/api2/json/ ',
        'api_token_id' => ' laravel@pam!package ',
        'api_token_secret' => 'token-secret',
        'timeout' => '15',
        'connect_timeout' => '5',
        'verify_ssl' => 'false',
        'retry' => ['times' => '2', 'sleep' => '250'],
    ]);

    expect(app(ProxmoxClient::class)->__debugInfo())->toMatchArray([
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
    config()->set('proxmox.connections.default.ca_bundle', '/etc/ssl/proxmox-ca.pem');

    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['data' => []]);
    });

    Proxmox::nodes();

    expect($options['verify'])->toBe('/etc/ssl/proxmox-ca.pem')
        ->and(app(ProxmoxClient::class)->__debugInfo()['caBundle'])->toBe('/etc/ssl/proxmox-ca.pem');
});

it('verifies TLS with an inline PEM certificate', function () {
    $pem = "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----";
    config()->set('proxmox.connections.default.ca_bundle', $pem);

    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['data' => []]);
    });

    Proxmox::nodes();

    expect($options['verify'])->toBeTrue()
        ->and($options['curl'][CURLOPT_CAINFO_BLOB])->toBe($pem)
        ->and(print_r(app(ProxmoxClient::class), true))->not->toContain('MIIB')->toContain('[PEM certificate]');
});

it('ignores the CA bundle when TLS verification is disabled', function () {
    config()->set('proxmox.connections.default.verify_ssl', false);
    config()->set('proxmox.connections.default.ca_bundle', '/etc/ssl/proxmox-ca.pem');

    $options = [];
    Http::fake(function (Request $request, array $sent) use (&$options) {
        $options = $sent;

        return Http::response(['data' => []]);
    });

    Proxmox::nodes();

    expect($options['verify'])->toBeFalse()
        ->and($options)->not->toHaveKey('curl');
});

it('builds runtime connections with connectUsing', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $client = Proxmox::connectUsing('runtime', [
        'url' => 'https://runtime.example.test:8006/api2/json',
        'api_token_id' => 'runtime@pve!app',
        'api_token_secret' => 'runtime-secret',
    ]);

    expect(Proxmox::connection('runtime'))->toBe($client);

    $client->nodes();

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://runtime.example.test'));
});

it('keeps the API token secret out of debug output', function () {
    $output = print_r(app(ProxmoxClient::class), true);

    expect($output)->not->toContain('token-secret')
        ->and($output)->toContain('laravel@pam!package');
});

it('retries GET requests after connection failures when configured', function () {
    config()->set('proxmox.connections.default.retry', ['times' => 2, 'sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->pushFailedConnection()
        ->push(['data' => [['node' => 'pve1']]]),
    ]);

    expect(collect(Proxmox::nodes())->pluck('node')->all())->toBe(['pve1']);
});

it('gives up after the configured number of GET retries', function () {
    config()->set('proxmox.connections.default.retry', ['times' => 1, 'sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->pushFailedConnection()
        ->push(['data' => []]),
    ]);

    expect(fn () => Proxmox::nodes())->toThrow(ConnectionException::class);
});

it('does not retry HTTP error responses', function () {
    config()->set('proxmox.connections.default.retry', ['times' => 2, 'sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->push(['errors' => ['node' => 'missing']], 500)
        ->push(['data' => []]),
    ]);

    expect(Proxmox::get('nodes')->status())->toBe(500);

    Http::assertSentCount(1);
});

it('never retries write requests', function (string $method) {
    config()->set('proxmox.connections.default.retry', ['times' => 2, 'sleep' => 0]);

    Http::fake(['*' => Http::sequence()
        ->pushFailedConnection()
        ->push(['data' => 'UPID:retried']),
    ]);

    expect(fn () => Proxmox::{$method}('nodes/pve1/qemu/101/status/start'))->toThrow(ConnectionException::class);
})->with(['post', 'put', 'delete']);

it('adds connection details without secrets to the about command', function () {
    $this->artisan('about', ['--only' => 'proxmox'])
        ->expectsOutputToContain('Default connection')
        ->expectsOutputToContain('default, backup')
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
