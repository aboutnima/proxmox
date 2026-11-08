<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    it('publishes configuration and the connection migration without changing the environment', function () {
        $environment = "APP_NAME=Laravel\nPROXMOX_CONNECTION=hetzner\n";
        file_put_contents($this->envPath.'/.env', $environment);

        $this->artisan('proxmox:install', ['--no-interaction' => true])->assertSuccessful();

        expect(config_path('proxmox.php'))->toBeFile()
            ->and(($this->publishedMigrations)())->toHaveCount(1)
            ->and(file_get_contents($this->envPath.'/.env'))->toBe($environment)
            ->and(Schema::hasTable('proxmox_clusters'))->toBeFalse();
    });

    it('preserves published configuration and does not duplicate migrations', function () {
        $this->artisan('proxmox:install', ['--no-interaction' => true])->assertSuccessful();
        $migrations = ($this->publishedMigrations)();
        file_put_contents(config_path('proxmox.php'), '<?php return ["default" => "hetzner"];');

        $this->artisan('proxmox:install', ['--no-interaction' => true])->assertSuccessful();

        expect(($this->publishedMigrations)())->toBe($migrations)
            ->and(file_get_contents(config_path('proxmox.php')))->toBe('<?php return ["default" => "hetzner"];');
    });

    it('can run the published migration when requested', function () {
        $this->artisan('proxmox:install', ['--migrate' => true, '--no-interaction' => true])->assertSuccessful();

        expect(Schema::hasTable('proxmox_clusters'))->toBeTrue()
            ->and(Schema::hasTable('proxmox_nodes'))->toBeFalse()
            ->and(Schema::hasTable('proxmox_virtual_machines'))->toBeFalse();
    });

    it('propagates migration failures without reporting a successful installation', function () {
        (new Filesystem)->ensureDirectoryExists(database_path('migrations'));
        file_put_contents(database_path('migrations/2020_01_01_000000_fail.php'), '<?php throw new RuntimeException("Migration failed");');

        expect(fn () => Artisan::call('proxmox:install', ['--migrate' => true, '--no-interaction' => true]))
            ->toThrow(RuntimeException::class, 'Migration failed');
    });
});

describe('proxmox:create-cluster', function () {
    beforeEach(function () {
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
    });

    it('stores prompted credentials encrypted without printing the secret', function () {
        $this->artisan('proxmox:create-cluster')
            ->expectsQuestion('Cluster name', 'hetzner')
            ->expectsQuestion('Proxmox API URL', 'https://pve.example.test:8006/api2/json')
            ->expectsQuestion('API token ID', 'laravel@pve!app')
            ->expectsQuestion('API token secret', 'typed-secret')
            ->doesntExpectOutputToContain('typed-secret')
            ->assertSuccessful();

        $cluster = Cluster::query()->sole();

        expect($cluster->name)->toBe('hetzner')
            ->and($cluster->api_token_secret)->toBe('typed-secret')
            ->and($cluster->verify_ssl)->toBeTrue()
            ->and(DB::table('proxmox_clusters')->value('api_token_secret'))->not->toBe('typed-secret');
    });

    it('stores a CA certificate file as connection settings', function () {
        $path = $this->envPath.'/ca.pem';
        file_put_contents($path, "-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----\n");

        $this->artisan('proxmox:create-cluster', ['name' => 'hetzner', '--url' => 'https://pve.example.test:8006/api2/json', '--token-id' => 'a@pve!b', '--ca-bundle' => $path])
            ->expectsQuestion('API token secret', 'typed-secret')
            ->doesntExpectOutputToContain('verification is disabled')
            ->assertSuccessful();

        expect(Cluster::query()->sole()->ca_bundle)->toBe("-----BEGIN CERTIFICATE-----\nMIIB\n-----END CERTIFICATE-----");
    });

    it('validates connection settings before storing a cluster', function (array $arguments, string $error) {
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

    it('rejects invalid and duplicate cluster names', function () {
        $this->artisan('proxmox:create-cluster', ['name' => 'eu.west'])
            ->expectsOutputToContain('may contain only letters, numbers, dashes, and underscores')
            ->assertFailed();

        Cluster::query()->create(['name' => 'hetzner', 'url' => 'https://pve.example.test', 'api_token_id' => 'a@pve!b', 'api_token_secret' => 'test-only']);

        $this->artisan('proxmox:create-cluster', ['name' => 'hetzner'])
            ->expectsOutputToContain('Cluster [hetzner] already exists.')
            ->assertFailed();
    });
});
