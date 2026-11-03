<?php

declare(strict_types=1);

namespace Proxmox\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Proxmox\Inventory;
use Proxmox\Models\Cluster;
use Throwable;

use function Laravel\Prompts\multiselect;

class SyncNodesCommand extends Command
{
    protected $signature = 'proxmox:sync-nodes
        {cluster?* : Names of the clusters to update}
        {--all : Update every active cluster}';

    protected $description = 'Fetch the nodes of Proxmox clusters and update the inventory';

    public function handle(): int
    {
        if (! Inventory::enabled()) {
            $this->components->error('Nodes are stored in inventory mode only. Run: php artisan proxmox:install --mode=inventory');

            return self::FAILURE;
        }

        $clusters = $this->clusters();

        if ($clusters === null) {
            return self::FAILURE;
        }

        $failed = false;

        foreach ($clusters as $cluster) {
            try {
                $result = $cluster->syncNodes();
            } catch (Throwable $exception) {
                $failed = true;
                $this->components->error("Cluster [{$cluster->name}]: {$exception->getMessage()}");

                continue;
            }

            $this->components->info("Cluster [{$cluster->name}]");

            foreach (['added' => 'green', 'updated' => 'yellow', 'unchanged' => 'gray', 'missing' => 'red'] as $change => $color) {
                foreach ($result[$change] as $node) {
                    $this->components->twoColumnDetail($node, "<fg={$color};options=bold>".strtoupper($change).'</>');
                }
            }

            if ($result['missing'] !== []) {
                $this->components->warn('Missing nodes are no longer listed by Proxmox. They are kept because virtual machines may reference them.');
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Cluster>|null
     */
    protected function clusters(): ?Collection
    {
        $model = Inventory::clusterModel();

        $names = array_values(array_filter((array) $this->argument('cluster'), is_string(...)));

        if ($this->option('all')) {
            $clusters = $model::query()->active()->orderBy('name')->get();
        } elseif ($names !== []) {
            $clusters = $model::query()->whereIn('name', $names)->orderBy('name')->get();
            $unknown = array_diff($names, $clusters->map(fn (Cluster $cluster): string => $cluster->name)->all());

            if ($unknown !== []) {
                $this->components->error('Unknown clusters: '.implode(', ', $unknown).'.');

                return null;
            }
        } elseif ($this->input->isInteractive()) {
            $available = $model::query()->orderBy('name')->get();

            if ($available->isEmpty()) {
                $this->components->error('No clusters exist. Add one with: php artisan proxmox:create-cluster');

                return null;
            }

            $selected = multiselect(
                label: 'Which clusters do you want to update?',
                options: $available->mapWithKeys(fn (Cluster $cluster): array => [
                    $cluster->name => $cluster->is_active ? $cluster->name : "{$cluster->name} (inactive)",
                ])->all(),
                default: $available->where('is_active', true)->map(fn (Cluster $cluster): string => $cluster->name)->values()->all(),
                required: true,
            );

            $clusters = $available->whereIn('name', $selected)->values();
        } else {
            $this->components->error('Pass cluster names or --all when running without interaction.');

            return null;
        }

        if ($clusters->isEmpty()) {
            $this->components->warn('No active clusters to update.');
        }

        return $clusters;
    }
}
