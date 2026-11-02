<?php

declare(strict_types=1);

namespace Proxmox\Resources\Nodes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Proxmox;
use Proxmox\Resources\Images\Images;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Resources\VirtualMachines\VirtualMachine;
use Proxmox\Resources\VirtualMachines\VirtualMachines;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResponseData;

readonly class Node
{
    public string $name;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private Proxmox $client, string $name)
    {
        $this->name = Identifier::node($name);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function status(): array
    {
        return ResponseData::attributes($this->client->get('nodes/'.rawurlencode($this->name).'/status'));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function get(): array
    {
        return $this->status();
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function first(): array
    {
        return $this->status();
    }

    public function virtualMachines(): VirtualMachines
    {
        return new VirtualMachines($this->client, $this->name);
    }

    /**
     * @return VirtualMachine
     * @throws InvalidArgumentException
     */
    public function virtualMachine(int|string|null $vmid = null): VirtualMachines|VirtualMachine
    {
        return $vmid === null
            ? $this->virtualMachines()
            : new VirtualMachine($this->client, $this->name, $vmid);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function images(string $storage): Images
    {
        return new Images($this->client, $this->name, $storage);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function task(string $upid): Task
    {
        return new Task($this->client, $this->name, $upid);
    }
}
