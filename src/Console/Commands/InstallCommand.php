<?php

declare(strict_types=1);

namespace Proxmox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Carbon;

use function Laravel\Prompts\confirm;

class InstallCommand extends Command
{
    protected $signature = 'proxmox:install
        {--migrate : Run pending database migrations after publishing}';

    protected $description = 'Publish the Proxmox configuration and cluster connection migration';

    public function handle(Filesystem $files): int
    {
        $target = config_path('proxmox.php');

        if (! $files->exists($target)) {
            $files->ensureDirectoryExists(dirname($target));
            $files->copy(__DIR__.'/../../../config/proxmox.php', $target);
        }

        $this->publishMigrations($files);

        if ($this->option('migrate') || ($this->input->isInteractive() && confirm('Run the database migrations now?', default: false))) {
            if ($this->call('migrate') !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->components->info('Proxmox is configured. Add a connection with: php artisan proxmox:create-cluster');

        return self::SUCCESS;
    }

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
}
