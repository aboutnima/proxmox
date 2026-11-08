<?php

declare(strict_types=1);

namespace Proxmox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Proxmox\ProxmoxManager;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateClusterCommand extends Command
{
    protected $signature = 'proxmox:create-cluster
        {name? : A unique cluster name, used with ProxmoxCluster::connection($name)}
        {--url= : The Proxmox API URL, including /api2/json}
        {--token-id= : The API token ID, such as laravel@pve!app}
        {--timeout=30 : Seconds allowed for each request}
        {--connect-timeout=10 : Seconds allowed to open the connection}
        {--ca-bundle= : Path to a CA certificate file to store with the cluster}
        {--insecure : Disable TLS certificate verification}';

    protected $description = 'Store a named Proxmox cluster connection';

    public function handle(Filesystem $files, ProxmoxManager $manager): int
    {
        $model = $manager->clusterModel();
        $name = $this->stringInput($this->argument('name')) ?? text(
            label: 'Cluster name',
            placeholder: 'default',
            required: true,
            hint: 'Used with ProxmoxCluster::connection($name).',
        );

        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/', $name) !== 1) {
            $this->components->error('The cluster name may contain only letters, numbers, dashes, and underscores.');

            return self::FAILURE;
        }

        if ($model::query()->where('name', $name)->exists()) {
            $this->components->error("Cluster [{$name}] already exists.");

            return self::FAILURE;
        }

        $attributes = $this->attributesFromInput($files);

        if ($attributes === null) {
            return self::FAILURE;
        }

        $model::query()->create([
            'name' => $name,
            ...$attributes,
        ]);

        $this->components->info("Cluster [{$name}] created.");

        if ($attributes['verify_ssl'] === false) {
            $this->components->warn('TLS certificate verification is disabled for this cluster. Prefer --ca-bundle with the Proxmox CA (/etc/pve/pve-root-ca.pem).');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function attributesFromInput(Filesystem $files): ?array
    {
        $url = $this->stringInput($this->option('url')) ?? text(
            label: 'Proxmox API URL',
            placeholder: 'https://proxmox.example.com:8006/api2/json',
            required: true,
        );

        $tokenId = $this->stringInput($this->option('token-id')) ?? text(
            label: 'API token ID',
            placeholder: 'laravel@pve!app',
            required: true,
        );

        $secret = $this->input->isInteractive()
            ? password(label: 'API token secret', required: true)
            : '';

        $caBundle = $this->stringInput($this->option('ca-bundle'));

        return $this->validated($files, [
            'url' => trim($url),
            'api_token_id' => trim($tokenId),
            'api_token_secret' => trim($secret),
            'timeout' => (int) $this->option('timeout'),
            'connect_timeout' => (int) $this->option('connect-timeout'),
            'verify_ssl' => ! $this->option('insecure'),
        ], $caBundle);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>|null
     */
    protected function validated(Filesystem $files, array $attributes, ?string $caBundle): ?array
    {
        $error = match (true) {
            ! is_string($attributes['url']) || preg_match('#^https?://[^\s/]+#i', $attributes['url']) !== 1 => 'The URL must start with http:// or https://.',
            ! is_string($attributes['api_token_id']) || preg_match('/^[^@\s]+@[^!\s]+![^\s]+$/', $attributes['api_token_id']) !== 1 => 'The API token ID must look like user@realm!token.',
            $attributes['api_token_secret'] === '' => 'The API token secret is required. Run the command interactively to enter it.',
            $attributes['timeout'] < 1 || $attributes['connect_timeout'] < 1 => 'Timeouts must be greater than zero.',
            default => null,
        };

        if ($error !== null) {
            $this->components->error($error);

            return null;
        }

        if ($caBundle === null || str_contains($caBundle, '-----BEGIN CERTIFICATE-----')) {
            return [...$attributes, 'ca_bundle' => $caBundle];
        }

        if (! $files->isFile($caBundle) || ! str_contains($certificate = $files->get($caBundle), '-----BEGIN CERTIFICATE-----')) {
            $this->components->error("The CA bundle [{$caBundle}] is not a readable PEM certificate file.");

            return null;
        }

        return [...$attributes, 'ca_bundle' => trim($certificate)];
    }

    private function stringInput(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
