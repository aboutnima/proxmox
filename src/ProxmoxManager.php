<?php

declare(strict_types=1);

namespace Proxmox;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Traits\ForwardsCalls;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Models\Cluster;

/**
 * Builds fresh API clients from stored cluster connections.
 *
 * @mixin Proxmox
 */
class ProxmoxManager
{
    use ForwardsCalls;

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

        return $this->resolve($name);
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
     * @return class-string<Cluster>
     *
     * @throws InvalidConfigurationException
     *
     * @internal
     */
    public function clusterModel(): string
    {
        $model = $this->config->get('proxmox.model', Cluster::class);

        if (! is_string($model) || ! is_a($model, Cluster::class, true)) {
            throw InvalidConfigurationException::invalidClusterModel();
        }

        return $model;
    }

    /**
     * Build a standalone client from runtime settings without registering it.
     *
     * @param  array<array-key, mixed>  $config
     */
    public function connectUsing(string $name, array $config): Proxmox
    {
        return Proxmox::fromConfig($this->http, $config, $name);
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
        $model = $this->clusterModel();
        $cluster = $model::query()->where('name', $name)->first();

        if ($cluster === null) {
            throw InvalidConfigurationException::clusterNotFound($name);
        }

        return Proxmox::fromConfig($this->http, $cluster->toConnectionConfig(), $name);
    }
}
