<?php

declare(strict_types=1);

namespace Proxmox;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Traits\ForwardsCalls;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Models\Cluster;

/**
 * Resolves and caches API clients from stored cluster connections.
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
     * Forget a resolved connection so it is rebuilt from the database on next use.
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
        $cluster = Cluster::query()->where('name', $name)->first();

        if ($cluster === null) {
            throw InvalidConfigurationException::clusterNotFound($name);
        }

        return Proxmox::fromConfig($this->http, $cluster->toConnectionConfig(), $name);
    }
}
