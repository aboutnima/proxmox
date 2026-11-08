<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('proxmox_clusters') && ! Schema::hasTable('proxmox_cluster')) {
            Schema::rename('proxmox_clusters', 'proxmox_cluster');
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('proxmox_cluster') && ! Schema::hasTable('proxmox_clusters')) {
            Schema::rename('proxmox_cluster', 'proxmox_clusters');
        }
    }
};
