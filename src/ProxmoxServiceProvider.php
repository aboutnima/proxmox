<?php

declare(strict_types=1);

namespace Proxmox;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;

class ProxmoxServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/proxmox.php', 'proxmox');

        $this->app->singleton(ProxmoxManager::class, fn (Container $app): ProxmoxManager => new ProxmoxManager(
            $app->make(Repository::class),
            $app->make(Factory::class),
        ));

        $this->app->bind(Proxmox::class, fn (Container $app): Proxmox => $app->make(ProxmoxManager::class)->connection());
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

        if (class_exists(AboutCommand::class)) {
            AboutCommand::add('Proxmox', fn (): array => [
                'Default connection' => $this->app->make(ProxmoxManager::class)->getDefaultConnection(),
                'Connections' => implode(', ', array_keys((array) config('proxmox.connections', []))),
            ]);
        }
    }
}
