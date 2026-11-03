<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Proxmox\Facades\Proxmox as ProxmoxFacade;
use Proxmox\Proxmox;

beforeEach(function () {
    config()->set([
        'proxmox.connections.default.url' => 'https://proxmox.example.test:8006/api2/json',
        'proxmox.connections.default.api_token_id' => 'laravel@pam!package',
        'proxmox.connections.default.api_token_secret' => 'token-secret',
        'proxmox.connections.default.timeout' => 15,
        'proxmox.connections.default.verify_ssl' => true,
    ]);

    Http::fake([
        '*/nodes/my/qemu' => Http::response(['data' => [['vmid' => 101, 'status' => 'running']]]),
        '*' => Http::response(['data' => [['node' => 'pve1']]]),
    ]);
});

it('sends authenticated GET requests to the configured API URL', function () {
    $response = ProxmoxFacade::get('/nodes', ['type' => 'qemu']);

    expect($response->json('data'))->toBe([['node' => 'pve1']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes?type=qemu'
        && $request->hasHeader('Authorization', 'PVEAPIToken=laravel@pam!package=token-secret')
        && $request->hasHeader('Accept', 'application/json'));
});

it('gets the available nodes', function () {
    $nodes = ProxmoxFacade::nodes();

    expect($nodes)->toBeArray()->toBe([['node' => 'pve1']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes');
});

it('gets QEMU virtual machines for a node', function () {
    $vms = ProxmoxFacade::node('my')->virtualMachines()->all();

    expect($vms)->toBeArray()->toBe([['vmid' => 101, 'status' => 'running']]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/my/qemu');
});

it('rejects invalid node names when listing virtual machines', function () {
    foreach (['', '../other', 'my/node'] as $node) {
        expect(fn () => ProxmoxFacade::node($node)->virtualMachines()->all())
            ->toThrow(InvalidArgumentException::class, 'node');
    }

    Http::assertNothingSent();
});

it('sends write request data as form parameters', function () {
    app(Proxmox::class)->post('/nodes/pve1/qemu', [
        'vmid' => 101,
        'name' => 'web',
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/pve1/qemu'
        && $request->body() === 'vmid=101&name=web'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
});

it('sends PUT request data as form parameters', function () {
    app(Proxmox::class)->put('/nodes/pve1/qemu/101/config', [
        'cores' => 2,
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->body() === 'cores=2'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
});

it('sends DELETE parameters in the query string because Proxmox ignores DELETE bodies', function () {
    app(Proxmox::class)->delete('/nodes/pve1/qemu/101', [
        'purge' => 1,
        'destroy-unreferenced-disks' => 1,
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/pve1/qemu/101?purge=1&destroy-unreferenced-disks=1'
        && $request->body() === '');
});

it('supports arbitrary HTTP methods and request options', function () {
    app(Proxmox::class)->request('delete', '/nodes/pve1/qemu/101', [
        'query' => ['purge' => 1],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
        && $request->url() === 'https://proxmox.example.test:8006/api2/json/nodes/pve1/qemu/101?purge=1');
});

it('uses form encoding for generic requests with form parameters', function () {
    app(Proxmox::class)->request('post', '/nodes/pve1/qemu', [
        'form_params' => ['vmid' => 102],
    ]);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->body() === 'vmid=102'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
});

it('fails clearly when the API URL is missing', function () {
    config()->set('proxmox.connections.default.url', '');

    expect(fn () => app(Proxmox::class)->get('/nodes'))
        ->toThrow(LogicException::class, 'PROXMOX_URL');

    Http::assertNothingSent();
});

it('fails clearly when required API configuration is missing', function () {
    config()->set('proxmox.connections.default.api_token_secret', '');

    expect(fn () => app(Proxmox::class)->get('/nodes'))
        ->toThrow(LogicException::class, 'PROXMOX_API_TOKEN_SECRET');

    Http::assertNothingSent();
});

it('requires a positive request timeout', function () {
    config()->set('proxmox.connections.default.timeout', 0);

    expect(fn () => app(Proxmox::class)->get('/nodes'))
        ->toThrow(LogicException::class, 'PROXMOX_TIMEOUT');

    Http::assertNothingSent();
});

it('rejects absolute URLs as API endpoints', function () {
    expect(fn () => app(Proxmox::class)->get('https://attacker.example.test/collect'))
        ->toThrow(InvalidArgumentException::class, 'relative');

    Http::assertNothingSent();
});
