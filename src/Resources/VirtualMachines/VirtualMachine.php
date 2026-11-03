<?php

declare(strict_types=1);

namespace Proxmox\Resources\VirtualMachines;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use JsonSerializable;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Proxmox;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResponseData;

/**
 * A VM's fetched runtime data and node-scoped management operations.
 *
 * @implements Arrayable<array-key, mixed>
 */
class VirtualMachine implements Arrayable, JsonSerializable
{
    public readonly string $node;

    public readonly int $vmid;

    /**
     * @var array<array-key, mixed>
     */
    private array $attributes = [];

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private readonly Proxmox $client, string $node, int|string $vmid)
    {
        $this->node = Identifier::node($node);
        $this->vmid = Identifier::vmid($vmid);
    }

    /**
     * Fetch the current runtime status without changing the stored data.
     *
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function status(): array
    {
        return ResponseData::attributes($this->client->get($this->path('status/current')));
    }

    /**
     * Replace the stored data with the latest runtime status.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function refresh(): static
    {
        $this->attributes = $this->status();

        return $this;
    }

    /**
     * Return the stored runtime data without sending a request.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the current VM configuration (cores, memory, disks, network cards, ...).
     *
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function config(): array
    {
        return ResponseData::attributes($this->client->get($this->path('config')));
    }

    /**
     * Set the CPU cores per socket, and optionally the socket count.
     *
     * Without CPU hotplug, Proxmox may keep the change pending until a running VM restarts.
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function setCpu(int $cores, ?int $sockets = null): Task
    {
        if ($cores < 1) {
            throw new InvalidArgumentException('cores must be a positive integer.');
        }

        if ($sockets !== null && $sockets < 1) {
            throw new InvalidArgumentException('sockets must be a positive integer.');
        }

        return $this->configure(array_filter(['cores' => $cores, 'sockets' => $sockets], is_int(...)));
    }

    /**
     * Set the memory in MiB.
     *
     * Without memory hotplug, Proxmox may keep the change pending until a running VM restarts.
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function setMemory(int $megabytes): Task
    {
        if ($megabytes < 1) {
            throw new InvalidArgumentException('megabytes must be a positive integer in MiB.');
        }

        return $this->configure(['memory' => $megabytes]);
    }

    /**
     * Grow a disk such as "scsi0" by a number of GiB. Proxmox cannot shrink disks.
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function growDisk(string $disk, int $gigabytes): Task
    {
        if (preg_match('/^[a-z]+[0-9]+$/D', $disk) !== 1) {
            throw new InvalidArgumentException('disk must be a disk key such as scsi0 or virtio1.');
        }

        if ($gigabytes < 1) {
            throw new InvalidArgumentException('gigabytes must be a positive integer.');
        }

        return new Task($this->client, $this->node, ResponseData::taskId(
            $this->client->put($this->path('resize'), ['disk' => $disk, 'size' => '+'.$gigabytes.'G']),
        ));
    }

    /**
     * @param  array<string, int>  $parameters
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    private function configure(array $parameters): Task
    {
        return new Task($this->client, $this->node, ResponseData::taskId(
            $this->client->post($this->path('config'), $parameters),
        ));
    }

    private function path(string $path): string
    {
        return 'nodes/'.rawurlencode($this->node).'/qemu/'.$this->vmid.'/'.$path;
    }
}
