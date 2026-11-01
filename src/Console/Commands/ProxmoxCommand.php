<?php

declare(strict_types=1);

namespace Proxmox\Proxmox\Console\Commands;

use Illuminate\Console\Command;

class ProxmoxCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'proxmox:placeholder';

    /**
     * The command description.
     */
    protected $description = 'Placeholder Artisan command shipped by the package proxmox.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->line('Proxmox placeholder command executed.');

        return self::SUCCESS;
    }
}
