<?php

declare(strict_types=1);

namespace Proxmox\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Inventory;
use Proxmox\Proxmox;
use Proxmox\ProxmoxManager;

/**
 * A Proxmox cluster and the API token used to reach it.
 *
 * @property int $id
 * @property string $name
 * @property string $url
 * @property string $api_token_id
 * @property string $api_token_secret
 * @property int $timeout
 * @property int $connect_timeout
 * @property bool $verify_ssl
 * @property string|null $ca_bundle
 * @property int $retry_times
 * @property int $retry_sleep
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Cluster extends Model
{
    protected $table = 'proxmox_clusters';

    protected $fillable = [
        'name',
        'url',
        'api_token_id',
        'api_token_secret',
        'timeout',
        'connect_timeout',
        'verify_ssl',
        'ca_bundle',
        'retry_times',
        'retry_sleep',
        'is_active',
    ];

    protected $hidden = [
        'api_token_secret',
    ];

    protected $attributes = [
        'timeout' => 30,
        'connect_timeout' => 10,
        'verify_ssl' => true,
        'retry_times' => 0,
        'retry_sleep' => 100,
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_token_secret' => 'encrypted',
            'timeout' => 'integer',
            'connect_timeout' => 'integer',
            'verify_ssl' => 'boolean',
            'retry_times' => 'integer',
            'retry_sleep' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Cluster $cluster): void {
            $manager = app(ProxmoxManager::class);

            $manager->purge($cluster->name);

            if ($cluster->wasChanged('name') && is_string($original = $cluster->getOriginal('name'))) {
                $manager->purge($original);
            }
        });

        static::deleted(fn (Cluster $cluster) => app(ProxmoxManager::class)->purge($cluster->name));
    }

    /**
     * @return HasMany<Node, $this>
     *
     * @throws InvalidConfigurationException
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(Inventory::nodeModel(), 'cluster_id');
    }

    /**
     * @return HasMany<VirtualMachine, $this>
     *
     * @throws InvalidConfigurationException
     */
    public function virtualMachines(): HasMany
    {
        return $this->hasMany(Inventory::virtualMachineModel(), 'cluster_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The API client for this cluster, shared with Proxmox::connection($name).
     *
     * @throws InvalidConfigurationException
     */
    public function client(): Proxmox
    {
        return app(ProxmoxManager::class)->connection($this->name);
    }

    /**
     * Settings in the "proxmox.connections.*" format accepted by Proxmox::fromConfig().
     *
     * @return array{url: string, api_token_id: string, api_token_secret: string, timeout: int, connect_timeout: int, verify_ssl: bool, ca_bundle: string|null, retry: array{times: int, sleep: int}}
     */
    public function toConnectionConfig(): array
    {
        return [
            'url' => $this->url,
            'api_token_id' => $this->api_token_id,
            'api_token_secret' => $this->api_token_secret,
            'timeout' => $this->timeout,
            'connect_timeout' => $this->connect_timeout,
            'verify_ssl' => $this->verify_ssl,
            'ca_bundle' => $this->ca_bundle,
            'retry' => [
                'times' => $this->retry_times,
                'sleep' => $this->retry_sleep,
            ],
        ];
    }
}
