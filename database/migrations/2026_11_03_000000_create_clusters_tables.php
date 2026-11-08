<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clusters', function (Blueprint $table): void {
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
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clusters');
    }
};
