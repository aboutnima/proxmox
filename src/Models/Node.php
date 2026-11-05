<?php

declare(strict_types=1);

namespace Proxmox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Inventory;
use Proxmox\Resources\Nodes\NodeResource;

/**
 * A node of a Proxmox cluster, identified by its name within the cluster.
 *
 * @property int $id
 * @property int $cluster_id
 * @property string $name
 * @property string|null $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Cluster $cluster
 */
class Node extends Model
{
    /**
     * Status of a node that Proxmox no longer lists for its cluster.
     */
    public const string MISSING = 'missing';

    protected $table = 'proxmox_nodes';

    protected $fillable = [
        'cluster_id',
        'name',
        'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cluster_id' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Cluster, $this>
     *
     * @throws InvalidConfigurationException
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Inventory::clusterModel(), 'cluster_id');
    }

    /**
     * @return HasMany<VirtualMachine, $this>
     *
     * @throws InvalidConfigurationException
     */
    public function virtualMachines(): HasMany
    {
        return $this->hasMany(Inventory::virtualMachineModel(), 'node_id');
    }

    /**
     * Select this node on its cluster's API client without sending a request.
     *
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function resource(): NodeResource
    {
        return NodeResource::make($this);
    }

    /**
     * Select this node on its cluster's API client without sending a request.
     *
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function client(): NodeResource
    {
        return $this->resource();
    }
}
