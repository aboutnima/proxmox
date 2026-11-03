<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Proxmox\Models\Cluster;

beforeEach(function () {
    $this->envPath = sys_get_temp_dir().'/proxmox-install-'.uniqid();
    (new Filesystem)->ensureDirectoryExists($this->envPath.'/config');
    (new Filesystem)->ensureDirectoryExists($this->envPath.'/database');

    app()->useEnvironmentPath($this->envPath);
    app()->useConfigPath($this->envPath.'/config');
    app()->useDatabasePath($this->envPath.'/database');

    $this->publishedMigrations = fn (): array => glob(database_path('migrations/*_create_proxmox_tables.php')) ?: [];
});

afterEach(function () {
    (new Filesystem)->deleteDirectory($this->envPath);
});

describe('proxmox:install', function () {
    it('configures api mode without publishing migrations', function () {
        file_put_contents($this->envPath.'/.env', "APP_NAME=Laravel\nPROXMOX_MODE=inventory\n");

        $this->artisan('proxmox:install', ['--mode' => 'api'])
            ->expectsOutputToContain('Proxmox is configured in api mode.')
            ->assertSuccessful();

        expect(file_get_contents($this->envPath.'/.env'))->toBe("APP_NAME=Laravel\nPROXMOX_MODE=api\n")
            ->and(config_path('proxmox.php'))->toBeFile()
            ->and(($this->publishedMigrations)())->toBe([]);
    });

    it('asks for the mode when it is not given', function () {
        file_put_contents($this->envPath.'/.env', 'APP_NAME=Laravel');

        $this->artisan('proxmox:install')
            ->expectsChoice('Which mode do you want to configure?', 'api', [
                'api' => 'API only: connections from .env, no database',
                'inventory' => 'Inventory: API + database clusters, nodes, and virtual machines',
            ])
            ->assertSuccessful();

        expect(file_get_contents($this->envPath.'/.env'))->toBe("APP_NAME=Laravel\nPROXMOX_MODE=api\n");
    });

    it('publishes the config and migrations once in inventory mode', function () {
        file_put_contents($this->envPath.'/.env', "APP_NAME=Laravel\n");

        $this->artisan('proxmox:install', ['--mode' => 'inventory'])
            ->expectsConfirmation('Run the database migrations now?', 'no')
            ->expectsOutputToContain('Proxmox is configured in inventory mode.')
            ->assertSuccessful();

        Artisan::call('proxmox:install', ['--mode' => 'inventory', '--no-interaction' => true]);

        expect(Artisan::output())
            ->toContain('php artisan proxmox:create-cluster')
            ->toContain('Proxmox\Concerns\HasVirtualMachines')
            ->toContain('EXISTS');

        $published = array_map(basename(...), ($this->publishedMigrations)());

        expect(file_get_contents($this->envPath.'/.env'))->toBe("APP_NAME=Laravel\nPROXMOX_MODE=inventory\n")
            ->and(preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $published))->toBe([
                'create_proxmox_tables.php',
            ]);
    });

    it('runs the inventory migrations when asked', function () {
        $this->artisan('proxmox:install', ['--mode' => 'inventory', '--migrate' => true])->assertSuccessful();

        expect(DB::getSchemaBuilder()->hasTable('proxmox_virtual_machines'))->toBeTrue();
    });

    it('warns that env connections must be moved when switching to inventory mode', function () {
        config()->set('proxmox.connections.default.url', 'https://pve.example.test:8006/api2/json');
        config()->set('proxmox.connections.backup', ['url' => 'https://backup.example.test:8006/api2/json']);
        config()->set('proxmox.connections.empty', ['url' => '']);

        Artisan::call('proxmox:install', ['--mode' => 'inventory', '--no-interaction' => true]);

        expect(Artisan::output())
            ->toContain('Your .env connections are no longer used')
            ->toContain('php artisan proxmox:create-cluster default --from-env')
            ->toContain('php artisan proxmox:create-cluster backup --from-env')
            ->not->toContain('create-cluster empty');
    });

    it('does not warn about env connections when already in inventory mode', function () {
        config()->set('proxmox.mode', 'inventory');
        config()->set('proxmox.connections.default.url', 'https://pve.example.test:8006/api2/json');

        $this->artisan('proxmox:install', ['--mode' => 'inventory', '--no-interaction' => true])
            ->doesntExpectOutputToContain('no longer used')
            ->assertSuccessful();
    });

    it('warns that database clusters are unused when switching back to api mode', function () {
        config()->set('proxmox.mode', 'inventory');

        $this->artisan('proxmox:install', ['--mode' => 'api'])
            ->expectsOutputToContain('Clusters stored in the database are no longer used')
            ->assertSuccessful();
    });

    it('asks to set the mode manually without a .env file', function () {
        $this->artisan('proxmox:install', ['--mode' => 'api'])
            ->expectsOutputToContain('Set PROXMOX_MODE=api in your environment')
            ->assertSuccessful();
    });

    it('rejects unknown modes', function () {
        $this->artisan('proxmox:install', ['--mode' => 'database'])
            ->expectsOutputToContain('The mode must be "api" or "inventory"')
            ->assertFailed();

        expect(config_path('proxmox.php'))->not->toBeFile();
    });
});

describe('proxmox:create-cluster', function () {
    beforeEach(function () {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');

        config()->set('proxmox.mode', 'inventory');
        config()->set('proxmox.connections.default', [
            'url' => 'https://pve.example.test:8006/api2/json',
            'api_token_id' => 'laravel@pve!app',
            'api_token_secret' => 'env-secret',
            'timeout' => '20',
            'connect_timeout' => '5',
            'verify_ssl' => 'false',
            'ca_bundle' => null,
            'retry' => ['times' => '2', 'sleep' => '250'],
        ]);
    });

    it('moves an env connection into the database', function () {
        $this->artisan('proxmox:create-cluster', ['name' => 'default', '--from-env' => true])
            ->expectsOutputToContain('Cluster [default] created.')
            ->expectsOutputToContain('Remove the copied API token secret from your .env file')
            ->expectsOutputToContain('TLS certificate verification is disabled')
            ->doesntExpectOutputToContain('env-secret')
            ->assertSuccessful();

        expect(Cluster::query()->sole()->toConnectionConfig())->toBe([
            'url' => 'https://pve.example.test:8006/api2/json',
            'api_token_id' => 'laravel@pve!app',
            'api_token_secret' => 'env-secret',
            'timeout' => 20,
            'connect_timeout' => 5,
            'verify_ssl' => false,
            'ca_bundle' => null,
            'retry' => ['times' => 2, 'sleep' => 250],
        ]);
    });

    it('copies a different env connection under a new name', function () {
        $this->artisan('proxmox:create-cluster', ['name' => 'eu-1', '--from-env' => true, '--connection' => 'default'])
            ->assertSuccessful();

        expect(Cluster::query()->sole()->name)->toBe('eu-1');

        $this->artisan('proxmox:create-cluster', ['name' => 'us-1', '--from-env' => true])
            ->expectsOutputToContain('Connection [us-1] is not configured')
            ->assertFailed();
    });

    it('stores the contents of a CA file from an env connection', function () {
        $path = tempnam(sys_get_temp_dir(), 'pve-ca');
        file_put_contents($path, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");
        config()->set('proxmox.connections.default.ca_bundle', $path);
        config()->set('proxmox.connections.default.verify_ssl', true);

        $this->artisan('proxmox:create-cluster', ['name' => 'default', '--from-env' => true])
            ->doesntExpectOutputToContain('verification is disabled')
            ->assertSuccessful();

        unlink($path);

        expect(Cluster::query()->sole()->ca_bundle)->toBe("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----");
    });

    it('creates a cluster from prompts with a hidden secret', function () {
        $this->artisan('proxmox:create-cluster', ['--inactive' => true])
            ->expectsQuestion('Cluster name', 'eu-1')
            ->expectsQuestion('Proxmox API URL', 'https://eu.example.test:8006/api2/json')
            ->expectsQuestion('API token ID', 'laravel@pve!eu')
            ->expectsQuestion('API token secret', 'typed-secret')
            ->doesntExpectOutputToContain('typed-secret')
            ->assertSuccessful();

        $cluster = Cluster::query()->sole();

        expect($cluster->only(['name', 'url', 'api_token_id', 'api_token_secret', 'verify_ssl', 'is_active']))->toBe([
            'name' => 'eu-1',
            'url' => 'https://eu.example.test:8006/api2/json',
            'api_token_id' => 'laravel@pve!eu',
            'api_token_secret' => 'typed-secret',
            'verify_ssl' => true,
            'is_active' => false,
        ]);
    });

    it('validates cluster input', function (array $arguments, string $error) {
        $this->artisan('proxmox:create-cluster', $arguments)
            ->expectsQuestion('API token secret', 'typed-secret')
            ->expectsOutputToContain($error)
            ->assertFailed();

        expect(Cluster::query()->count())->toBe(0);
    })->with([
        'url' => [['name' => 'eu', '--url' => 'pve.example.test', '--token-id' => 'a@pve!b'], 'The URL must start with http:// or https://.'],
        'token id' => [['name' => 'eu', '--url' => 'https://pve.example.test', '--token-id' => 'laravel'], 'The API token ID must look like user@realm!token.'],
        'timeout' => [['name' => 'eu', '--url' => 'https://pve.example.test', '--token-id' => 'a@pve!b', '--timeout' => '0'], 'Timeouts must be greater than zero.'],
        'ca bundle' => [['name' => 'eu', '--url' => 'https://pve.example.test', '--token-id' => 'a@pve!b', '--ca-bundle' => '/missing/ca.pem'], 'is not a readable PEM certificate file'],
    ]);

    it('requires the secret when running without interaction', function () {
        $this->artisan('proxmox:create-cluster', ['name' => 'eu', '--url' => 'https://pve.example.test', '--token-id' => 'a@pve!b', '--no-interaction' => true])
            ->expectsOutputToContain('The API token secret is required')
            ->assertFailed();
    });

    it('rejects invalid and duplicate names', function () {
        $this->artisan('proxmox:create-cluster', ['name' => 'eu.west', '--from-env' => true, '--connection' => 'default'])
            ->expectsOutputToContain('may contain only letters, numbers, dashes, and underscores')
            ->assertFailed();

        $this->artisan('proxmox:create-cluster', ['name' => 'default', '--from-env' => true])->assertSuccessful();

        $this->artisan('proxmox:create-cluster', ['name' => 'default', '--from-env' => true])
            ->expectsOutputToContain('Cluster [default] already exists.')
            ->assertFailed();
    });

    it('requires inventory mode', function () {
        config()->set('proxmox.mode', 'api');

        $this->artisan('proxmox:create-cluster', ['name' => 'default', '--from-env' => true])
            ->expectsOutputToContain('php artisan proxmox:install --mode=inventory')
            ->assertFailed();
    });
});
