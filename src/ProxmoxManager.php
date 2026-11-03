<?php

declare(strict_types=1);

namespace Proxmox;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Traits\ForwardsCalls;
use Proxmox\Exceptions\InvalidConfigurationException;

/**
 * Resolves and caches one Proxmox client per connection: from config in api
 * mode, or from the proxmox_clusters table in inventory mode.
 *
 * @mixin Proxmox
 */
class ProxmoxManager
{
    use ForwardsCalls;

    /**
     * @var array<string, Proxmox>
     */
    protected array $connections = [];

    public function __construct(
        protected Repository $config,
        protected Factory $http,
    ) {}

    /**
     * @throws InvalidConfigurationException
     */
    public function connection(?string $name = null): Proxmox
    {
        $name ??= $this->getDefaultConnection();

        return $this->connections[$name] ??= $this->resolve($name);
    }

    public function getDefaultConnection(): string
    {
        $name = $this->config->get('proxmox.default');

        return is_string($name) && $name !== '' ? $name : 'default';
    }

    public function setDefaultConnection(string $name): void
    {
        $this->config->set('proxmox.default', $name);
    }

    /**
     * Build and cache a connection from a runtime configuration array.
     *
     * @param  array<array-key, mixed>  $config
     */
    public function connectUsing(string $name, array $config): Proxmox
    {
        return $this->connections[$name] = Proxmox::fromConfig($this->http, $config, $name);
    }

    /**
     * Forget a resolved connection so it is rebuilt from config on next use.
     */
    public function purge(?string $name = null): void
    {
        unset($this->connections[$name ?? $this->getDefaultConnection()]);
    }

    /**
     * @return array<string, Proxmox>
     */
    public function getConnections(): array
    {
        return $this->connections;
    }

    /**
     * @param  array<int, mixed>  $parameters
     *
     * @throws InvalidConfigurationException
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardCallTo($this->connection(), $method, $parameters);
    }

    /**
     * @throws InvalidConfigurationException
     */
    protected function resolve(string $name): Proxmox
    {
        if (Inventory::enabled()) {
            $cluster = Inventory::clusterModel()::query()->where('name', $name)->first();

            if ($cluster === null) {
                throw InvalidConfigurationException::clusterNotFound($name);
            }

            return Proxmox::fromConfig($this->http, $cluster->toConnectionConfig(), $name);
        }

        $config = $this->config->get("proxmox.connections.{$name}");

        if (! is_array($config)) {
            throw InvalidConfigurationException::connectionNotConfigured($name);
        }

        return Proxmox::fromConfig($this->http, $config, $name);
    }
}
