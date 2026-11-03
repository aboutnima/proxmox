<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Models\Cluster;
use Proxmox\Models\Node;
use Proxmox\Models\VirtualMachine;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

    config()->set('proxmox.mode', 'inventory');

    Http::preventStrayRequests();
});

function syncCluster(string $name, bool $active = true): Cluster
{
    return Cluster::query()->create([
        'name' => $name,
        'url' => "https://{$name}.example.test:8006/api2/json",
        'api_token_id' => 'laravel@pve!app',
        'api_token_secret' => 'secret',
        'is_active' => $active,
    ]);
}

function fakeNodes(array $nodesByCluster): void
{
    Http::fake(collect($nodesByCluster)->mapWithKeys(fn (array|int $nodes, string $cluster): array => [
        "https://{$cluster}.example.test:8006/api2/json/nodes" => is_int($nodes)
            ? Http::response(['errors' => 'down'], $nodes)
            : Http::response(['data' => $nodes]),
    ])->all());
}

describe('Cluster::syncNodes()', function () {
    it('adds, updates, keeps, and marks missing nodes', function () {
        $cluster = syncCluster('eu');
        $cluster->nodes()->createMany([
            ['name' => 'pve1', 'status' => 'online'],
            ['name' => 'pve2', 'status' => 'online'],
            ['name' => 'old', 'status' => 'online'],
        ]);
        $vm = VirtualMachine::withoutProxmox(fn () => $cluster->virtualMachines()->create([
            'node_id' => $cluster->nodes()->where('name', 'old')->value('id'),
            'vmid' => 101,
        ]));

        fakeNodes(['eu' => [
            ['node' => 'pve1', 'status' => 'online'],
            ['node' => 'pve2', 'status' => 'offline'],
            ['node' => 'pve3', 'status' => 'online'],
        ]]);

        expect($cluster->syncNodes())->toBe([
            'added' => ['pve3'],
            'updated' => ['pve2'],
            'unchanged' => ['pve1'],
            'missing' => ['old'],
        ])->and($cluster->nodes()->orderBy('name')->pluck('status', 'name')->all())->toBe([
            'old' => Node::MISSING,
            'pve1' => 'online',
            'pve2' => 'offline',
            'pve3' => 'online',
        ])->and($vm->fresh()->node->name)->toBe('old');
    });

    it('restores the status of a node that reappears', function () {
        $cluster = syncCluster('eu');
        $cluster->nodes()->create(['name' => 'pve1', 'status' => Node::MISSING]);

        fakeNodes(['eu' => [['node' => 'pve1', 'status' => 'online']]]);

        expect($cluster->syncNodes()['updated'])->toBe(['pve1'])
            ->and($cluster->nodes()->sole()->status)->toBe('online');
    });

    it('only changes the nodes of its own cluster', function () {
        $eu = syncCluster('eu');
        $us = syncCluster('us');
        $us->nodes()->create(['name' => 'pve1', 'status' => 'online']);

        fakeNodes(['eu' => [['node' => 'pve1', 'status' => 'online']]]);

        expect($eu->syncNodes()['added'])->toBe(['pve1'])
            ->and($us->nodes()->sole()->status)->toBe('online')
            ->and(Node::query()->count())->toBe(2);
    });

    it('leaves the inventory unchanged when the response is invalid', function () {
        $cluster = syncCluster('eu');
        $cluster->nodes()->create(['name' => 'pve1', 'status' => 'online']);

        fakeNodes(['eu' => [['node' => 'pve2', 'status' => 'online'], ['status' => 'online']]]);

        expect(fn () => $cluster->syncNodes())->toThrow(UnexpectedResponseException::class, 'Expected a node name');
        expect($cluster->nodes()->pluck('status', 'name')->all())->toBe(['pve1' => 'online']);
    });
});

describe('proxmox:sync-nodes', function () {
    it('asks which clusters to update with active clusters selected', function () {
        syncCluster('eu');
        syncCluster('us');
        syncCluster('lab', active: false);

        fakeNodes(['eu' => [['node' => 'pve1', 'status' => 'online']]]);

        $this->artisan('proxmox:sync-nodes')
            ->expectsChoice('Which clusters do you want to update?', ['eu'], [
                'eu' => 'eu',
                'lab' => 'lab (inactive)',
                'us' => 'us',
            ])
            ->expectsOutputToContain('Cluster [eu]')
            ->doesntExpectOutputToContain('Cluster [us]')
            ->assertSuccessful();

        expect(Node::query()->pluck('name')->all())->toBe(['pve1']);
    });

    it('updates the named clusters', function () {
        syncCluster('eu');
        syncCluster('lab', active: false);

        fakeNodes(['eu' => [['node' => 'pve1', 'status' => 'online']], 'lab' => [['node' => 'lab1', 'status' => 'online']]]);

        $this->artisan('proxmox:sync-nodes', ['cluster' => ['eu', 'lab']])->assertSuccessful();

        expect(Node::query()->orderBy('name')->pluck('name')->all())->toBe(['lab1', 'pve1']);
    });

    it('updates every active cluster with --all', function () {
        syncCluster('eu');
        syncCluster('us');
        syncCluster('lab', active: false);

        fakeNodes(['eu' => [['node' => 'pve1', 'status' => 'online']], 'us' => [['node' => 'pve9', 'status' => 'offline']]]);

        Artisan::call('proxmox:sync-nodes', ['--all' => true]);

        expect(Artisan::output())
            ->toContain('Cluster [eu]')->toContain('Cluster [us]')->not->toContain('Cluster [lab]')
            ->toContain('pve1')->toContain('ADDED')
            ->and(Node::query()->orderBy('name')->pluck('status', 'name')->all())->toBe(['pve1' => 'online', 'pve9' => 'offline']);
    });

    it('reports missing nodes', function () {
        syncCluster('eu')->nodes()->create(['name' => 'old', 'status' => 'online']);

        fakeNodes(['eu' => []]);

        Artisan::call('proxmox:sync-nodes', ['cluster' => ['eu']]);

        expect(Artisan::output())->toContain('MISSING')->toContain('kept because virtual machines may reference them');
    });

    it('continues with other clusters when one fails', function () {
        syncCluster('eu');
        syncCluster('us');

        fakeNodes(['eu' => 500, 'us' => [['node' => 'pve9', 'status' => 'online']]]);

        $this->artisan('proxmox:sync-nodes', ['--all' => true])
            ->expectsOutputToContain('Cluster [eu]:')
            ->assertFailed();

        expect(Node::query()->pluck('name')->all())->toBe(['pve9']);
    });

    it('rejects unknown clusters', function () {
        syncCluster('eu');

        $this->artisan('proxmox:sync-nodes', ['cluster' => ['eu', 'missing']])
            ->expectsOutputToContain('Unknown clusters: missing.')
            ->assertFailed();

        Http::assertNothingSent();
    });

    it('requires cluster names or --all without interaction', function () {
        $this->artisan('proxmox:sync-nodes', ['--no-interaction' => true])
            ->expectsOutputToContain('Pass cluster names or --all')
            ->assertFailed();
    });

    it('asks to create a cluster when none exist', function () {
        $this->artisan('proxmox:sync-nodes')
            ->expectsOutputToContain('php artisan proxmox:create-cluster')
            ->assertFailed();
    });

    it('warns when there are no active clusters', function () {
        syncCluster('lab', active: false);

        $this->artisan('proxmox:sync-nodes', ['--all' => true])
            ->expectsOutputToContain('No active clusters to update.')
            ->assertSuccessful();
    });

    it('requires inventory mode', function () {
        config()->set('proxmox.mode', 'api');

        $this->artisan('proxmox:sync-nodes', ['--all' => true])
            ->expectsOutputToContain('php artisan proxmox:install --mode=inventory')
            ->assertFailed();
    });
});
