<?php

declare(strict_types=1);

namespace Proxmox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Inventory;
use Proxmox\Resources\VirtualMachines\VirtualMachineResource;

/**
 * An inventory record for one QEMU virtual machine lifecycle.
 *
 * Saving or deleting a record changes the database only. Proxmox reuses VMIDs
 * after deletion, so a reused VMID gets a new row. The node is current placement.
 *
 * @property int $id
 * @property int $cluster_id
 * @property int|null $node_id
 * @property int $vmid
 * @property string|null $name
 * @property string|null $status
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Cluster $cluster
 * @property-read Node|null $node
 * @property-read Model|null $owner
 */
class VirtualMachine extends Model
{
    use SoftDeletes;

    protected $table = 'proxmox_virtual_machines';

    protected $fillable = [
        'cluster_id',
        'node_id',
        'vmid',
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
            'node_id' => 'integer',
            'vmid' => 'integer',
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
     * @return BelongsTo<Node, $this>
     *
     * @throws InvalidConfigurationException
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Inventory::nodeModel(), 'node_id');
    }

    /**
     * The model that owns this VM, such as a user or team.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Select this inventory VM for live API operations without fetching runtime data.
     *
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function client(): VirtualMachineResource
    {
        return VirtualMachineResource::make($this);
    }

    /**
     * Select this inventory VM for live API operations without fetching runtime data.
     *
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function resource(): VirtualMachineResource
    {
        return $this->client();
    }
}
