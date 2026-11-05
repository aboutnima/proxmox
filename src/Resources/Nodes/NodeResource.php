<?php

declare(strict_types=1);

namespace Proxmox\Resources\Nodes;

use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Models\Node as NodeModel;

/**
 * A node-scoped Proxmox API resource selected from inventory.
 */
readonly class NodeResource extends Node
{
    /**
     * Select an inventory node without fetching data or changing its record.
     *
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public static function make(NodeModel $record): self
    {
        if (! $record->exists) {
            throw new InvalidArgumentException('Pass a saved node inventory record.');
        }

        $cluster = $record->cluster
            ?? throw new InvalidConfigurationException("Node [{$record->name}] has no cluster in the inventory.");

        return new self($cluster->client(), $record->name);
    }
}
