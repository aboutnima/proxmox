<?php

declare(strict_types=1);

namespace Proxmox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;
use Proxmox\Inventory;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class InstallCommand extends Command
{
    protected $signature = 'proxmox:install
        {--mode= : The package mode: "api" or "inventory"}
        {--migrate : Run the inventory migrations after publishing them}';

    protected $description = 'Configure the Proxmox package in api or inventory mode';

    public function handle(Filesystem $files): int
    {
        $previousMode = config('proxmox.mode', Inventory::API);

        $mode = $this->option('mode') ?? select(
            label: 'Which mode do you want to configure?',
            options: [
                Inventory::API => 'API only: connections from .env, no database',
                Inventory::INVENTORY => 'Inventory: API + database clusters, nodes, and virtual machines',
            ],
            default: $previousMode === Inventory::INVENTORY ? Inventory::INVENTORY : Inventory::API,
        );

        if (! in_array($mode, [Inventory::API, Inventory::INVENTORY], true)) {
            $this->components->error('The mode must be "api" or "inventory".');

            return self::FAILURE;
        }

        $this->publishConfig($files);
        $this->writeMode($files, $mode);

        if ($mode === Inventory::INVENTORY) {
            $this->publishMigrations($files);

            if ($this->option('migrate') || ($this->input->isInteractive() && confirm('Run the database migrations now?', default: false))) {
                $this->call('migrate');
            }

            if ($previousMode !== Inventory::INVENTORY) {
                $this->warnAboutEnvConnections();
            }
        } elseif ($previousMode === Inventory::INVENTORY) {
            $this->components->warn('API mode reads connections from .env and config/proxmox.php. Clusters stored in the database are no longer used.');
        }

        if ($this->laravel->configurationIsCached()) {
            $this->components->warn('Your configuration is cached. Run "php artisan config:cache" to apply the new mode.');
        }

        $this->components->info("Proxmox is configured in {$mode} mode.");

        if ($mode === Inventory::INVENTORY) {
            $this->components->bulletList([
                'Add clusters with: php artisan proxmox:create-cluster',
                'Use the Proxmox\Concerns\HasVirtualMachines trait on models that own virtual machines.',
            ]);
        }

        return self::SUCCESS;
    }

    protected function publishConfig(Filesystem $files): void
    {
        $target = config_path('proxmox.php');

        if ($files->exists($target)) {
            $this->components->twoColumnDetail('config/proxmox.php', '<fg=yellow;options=bold>EXISTS</>');

            return;
        }

        $files->ensureDirectoryExists(dirname($target));
        $files->copy(__DIR__.'/../../../config/proxmox.php', $target);

        $this->components->twoColumnDetail('config/proxmox.php', '<fg=green;options=bold>PUBLISHED</>');
    }

    protected function writeMode(Filesystem $files, string $mode): void
    {
        $path = $this->laravel->environmentFilePath();

        if (! $files->exists($path)) {
            $this->components->warn("No .env file found. Set PROXMOX_MODE={$mode} in your environment.");

            return;
        }

        $contents = $files->get($path);
        $line = "PROXMOX_MODE={$mode}";

        $contents = preg_match('/^PROXMOX_MODE=.*$/m', $contents) === 1
            ? (string) preg_replace('/^PROXMOX_MODE=.*$/m', $line, $contents)
            : rtrim($contents, "\n").($contents === '' ? '' : "\n").$line."\n";

        $files->put($path, $contents);

        $this->components->twoColumnDetail('.env', "<fg=green;options=bold>{$line}</>");
    }

    /**
     * Copy package migrations that are not published yet, with fresh timestamps.
     *
     * Already published migrations are matched by name and skipped, so running
     * the installer again never duplicates them.
     */
    protected function publishMigrations(Filesystem $files): void
    {
        $target = database_path('migrations');
        $publishedAt = Carbon::now();

        $files->ensureDirectoryExists($target);

        foreach ($files->files(__DIR__.'/../../../database/migrations') as $migration) {
            $name = (string) preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $migration->getFilename());

            if ($files->glob("{$target}/*_{$name}") !== []) {
                $this->components->twoColumnDetail("database/migrations/*_{$name}", '<fg=yellow;options=bold>EXISTS</>');

                continue;
            }

            $publishedAt = $publishedAt->addSecond();
            $filename = $publishedAt->format('Y_m_d_His').'_'.$name;

            $files->copy($migration->getPathname(), "{$target}/{$filename}");

            $this->components->twoColumnDetail("database/migrations/{$filename}", '<fg=green;options=bold>PUBLISHED</>');
        }
    }

    protected function warnAboutEnvConnections(): void
    {
        $connections = collect((array) config('proxmox.connections', []))
            ->filter(fn (mixed $connection): bool => is_array($connection) && is_string($connection['url'] ?? null) && trim($connection['url']) !== '')
            ->keys();

        if ($connections->isEmpty()) {
            return;
        }

        $this->components->warn('Inventory mode reads clusters from the database only. Your .env connections are no longer used.');
        $this->components->bulletList($connections->map(
            fn (int|string $name): string => "Move [{$name}] with: php artisan proxmox:create-cluster {$name} --from-env",
        )->all());
    }
}
