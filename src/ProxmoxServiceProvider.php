<?php

declare(strict_types=1);

namespace Proxmox\Proxmox;

use Illuminate\Support\ServiceProvider;
use Proxmox\Proxmox\Console\Commands\ProxmoxCommand;

class ProxmoxServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/proxmox.php', 'proxmox');

        $this->app->singleton(Proxmox::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/proxmox.php' => config_path('proxmox.php'),
        ], ['proxmox', 'proxmox-config']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/proxmox'),
        ], ['proxmox', 'proxmox-assets']);

        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['proxmox', 'proxmox-migrations']);

        $this->commands([
            ProxmoxCommand::class,
        ]);
    }
}
