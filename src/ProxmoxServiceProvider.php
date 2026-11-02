<?php

declare(strict_types=1);

namespace Proxmox;

use Illuminate\Support\ServiceProvider;

class ProxmoxServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/proxmox.php', 'proxmox');

        $this->app->singleton(Proxmox::class, fn (): Proxmox => new Proxmox(
            baseUrl: (string) config('proxmox.url', ''),
            apiTokenId: (string) config('proxmox.api_token_id', ''),
            apiTokenSecret: (string) config('proxmox.api_token_secret', ''),
            timeout: (int) config('proxmox.timeout', 30),
            verifySsl: (bool) config('proxmox.verify_ssl', true),
        ));
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
    }
}
