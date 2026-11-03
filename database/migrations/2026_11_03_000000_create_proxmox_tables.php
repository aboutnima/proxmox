<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxmox_clusters', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('url');
            $table->string('api_token_id');
            $table->text('api_token_secret');
            $table->unsignedInteger('timeout')->default(30);
            $table->unsignedInteger('connect_timeout')->default(10);
            $table->boolean('verify_ssl')->default(true);
            $table->text('ca_bundle')->nullable();
            $table->unsignedInteger('retry_times')->default(0);
            $table->unsignedInteger('retry_sleep')->default(100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('proxmox_nodes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cluster_id')->constrained('proxmox_clusters')->restrictOnDelete();
            $table->string('name');
            $table->string('status')->nullable();
            $table->timestamps();

            $table->unique(['cluster_id', 'name']);
        });

        Schema::create('proxmox_virtual_machines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cluster_id')->constrained('proxmox_clusters')->restrictOnDelete();
            $table->foreignId('node_id')->nullable()->constrained('proxmox_nodes')->nullOnDelete();
            $table->unsignedInteger('vmid');
            $table->string('name')->nullable();
            $table->string('status')->nullable();
            $table->nullableMorphs('owner');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['cluster_id', 'vmid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxmox_virtual_machines');
        Schema::dropIfExists('proxmox_nodes');
        Schema::dropIfExists('proxmox_clusters');
    }
};
