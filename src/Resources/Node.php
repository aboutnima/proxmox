<?php

declare(strict_types=1);

namespace Proxmox\Resources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use LogicException;
use Proxmox\Proxmox;
use UnexpectedValueException;

readonly class Node
{
    public function __construct(
        private Proxmox $client,
        private string $name,
    ) {}

    /**
     * @return ($vmid is null ? VirtualMachines : VirtualMachine)
     */
    public function virtualMachine(int|string|null $vmid = null): VirtualMachines|VirtualMachine
    {
        return $vmid === null
            ? new VirtualMachines($this->client, $this->name)
            : $this->vm($vmid);
    }

    public function vm(int|string $vmid): VirtualMachine
    {
        return new VirtualMachine($this->client, $this->name, $vmid);
    }

    public function images(string $storage): Images
    {
        return new Images($this->client, $this->name, $storage);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function get(): array
    {
        return $this->client->getNode($this->name);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function first(): array
    {
        return $this->get();
    }
}
