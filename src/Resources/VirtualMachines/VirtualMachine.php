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
     * Start the VM.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function start(): Task
    {
        return $this->operate('start');
    }

    /**
     * Gracefully shut down the VM through its guest operating system.
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function shutdown(?int $timeout = null): Task
    {
        return $this->operate('shutdown', $this->timeoutParameters($timeout));
    }

    /**
     * Gracefully reboot the VM. Pending VM configuration changes are applied.
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function restart(?int $timeout = null): Task
    {
        return $this->operate('reboot', $this->timeoutParameters($timeout));
    }

    /**
     * Pause the VM while keeping its state in memory.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function pause(): Task
    {
        return $this->operate('suspend', ['todisk' => false]);
    }

    /**
     * Suspend the VM to disk. The VM state storage must be configured.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function hibernate(): Task
    {
        return $this->operate('suspend', ['todisk' => true]);
    }

    /**
     * Immediately stop the VM, like removing its power.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function stop(): Task
    {
        return $this->operate('stop');
    }

    /**
     * Immediately reset the VM, like pressing its hardware reset button.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function reset(): Task
    {
        return $this->operate('reset');
    }

    /**
     * Resume a paused or hibernated VM.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function resume(): Task
    {
        return $this->operate('resume');
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

    /**
     * @param  array<string, bool|int>  $parameters
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    private function operate(string $operation, array $parameters = []): Task
    {
        return new Task($this->client, $this->node, ResponseData::taskId(
            $this->client->post($this->path('status/'.$operation), $parameters),
        ));
    }

    /**
     * @return array{timeout?: int}
     *
     * @throws InvalidArgumentException
     */
    private function timeoutParameters(?int $timeout): array
    {
        if ($timeout === null) {
            return [];
        }

        if ($timeout < 0) {
            throw new InvalidArgumentException('timeout must be zero or a positive number of seconds.');
        }

        return ['timeout' => $timeout];
    }

    private function path(string $path): string
    {
        return 'nodes/'.rawurlencode($this->node).'/qemu/'.$this->vmid.'/'.$path;
    }
}
