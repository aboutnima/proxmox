<?php

declare(strict_types=1);

namespace Proxmox\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Inventory;
use Proxmox\Models\VirtualMachine;

/**
 * Add to a model, such as User or Team, that owns inventory virtual machines.
 *
 * @mixin Model
 */
trait HasVirtualMachines
{
    /**
     * @return MorphMany<VirtualMachine, $this>
     *
     * @throws InvalidConfigurationException
     */
    public function virtualMachines(): MorphMany
    {
        return $this->morphMany(Inventory::virtualMachineModel(), 'owner');
    }
}
