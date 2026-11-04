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
use Proxmox\Models\VirtualMachine as VirtualMachineModel;
use Proxmox\Proxmox;
use Proxmox\Resources\Nodes\Node as NodeResource;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResourceData;
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
     * Select an inventory VM without fetching runtime data or changing its record.
     *
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public static function make(VirtualMachineModel $record): self
    {
        if (! $record->exists || $record->trashed()) {
            throw new InvalidArgumentException('Pass a saved, non-deleted virtual machine inventory record.');
        }

        $node = $record->node
            ?? throw new InvalidConfigurationException("Virtual machine [{$record->vmid}] has no node in the inventory.");

        if ($node->cluster_id !== $record->cluster_id) {
            throw new InvalidConfigurationException("Node [{$node->name}] does not belong to cluster [{$record->cluster_id}].");
        }

        return new self($node->cluster->client(), $node->name, $record->vmid);
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
     * Fetch live usage. Memory is in MiB; network and disk I/O are byte counters since the VM started.
     *
     * @return array{
     *     status: string|null,
     *     uptime: int,
     *     cpu: array{vcpus: int, percent: float},
     *     memory: array{total: int, used: int, free: int},
     *     network: array{in: int, out: int},
     *     disk: array{read: int, write: int},
     * }
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function usage(): array
    {
        $status = $this->status();
        $total = ResourceData::mebibytes($status['maxmem'] ?? 0);
        $used = ResourceData::mebibytes($status['mem'] ?? 0);

        return [
            'status' => is_string($status['status'] ?? null) ? $status['status'] : null,
            'uptime' => ResourceData::integer($status['uptime'] ?? 0),
            'cpu' => [
                'vcpus' => ResourceData::integer($status['cpus'] ?? 0),
                'percent' => ResourceData::percent($status['cpu'] ?? 0),
            ],
            'memory' => ['total' => $total, 'used' => $used, 'free' => max(0, $total - $used)],
            'network' => [
                'in' => ResourceData::integer($status['netin'] ?? 0),
                'out' => ResourceData::integer($status['netout'] ?? 0),
            ],
            'disk' => [
                'read' => ResourceData::integer($status['diskread'] ?? 0),
                'write' => ResourceData::integer($status['diskwrite'] ?? 0),
            ],
        ];
    }

    /**
     * Fetch the applied CPU, memory (MiB), and disk (GiB) allocation, ignoring pending changes.
     *
     * "balloon" is the minimum memory with ballooning, or null when it is not configured or disabled.
     *
     * @return array{
     *     cpu: array{sockets: int, cores: int, vcpus: int},
     *     memory: array{size: int, balloon: int|null},
     *     disks: array<string, array{storage: string|null, size: int}>,
     * }
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function resources(): array
    {
        $config = ResponseData::attributes($this->client->get($this->path('config'), ['current' => 1]));
        $sockets = ResourceData::integer($config['sockets'] ?? 1) ?: 1;
        $cores = ResourceData::integer($config['cores'] ?? 1) ?: 1;
        $balloon = ResourceData::integer($config['balloon'] ?? 0);

        return [
            'cpu' => [
                'sockets' => $sockets,
                'cores' => $cores,
                'vcpus' => ResourceData::integer($config['vcpus'] ?? 0) ?: $sockets * $cores,
            ],
            'memory' => [
                'size' => ResourceData::memory($config['memory'] ?? null),
                'balloon' => $balloon > 0 ? $balloon : null,
            ],
            'disks' => ResourceData::disks($config),
        ];
    }

    /**
     * Fetch how far the VM can grow with the node's free CPU, memory (MiB), and storage (GiB).
     *
     * "max" is the largest value to pass to setCpu() (cores x sockets), setMemory(), or the
     * resulting disk size; "additional" is the room left above the current allocation. A stopped VM
     * only counts free node memory, while a running VM also keeps its current allocation. Disks on
     * storage that is not listed for VM images have null values.
     *
     * @return array{
     *     cpu: array{max: int, additional: int},
     *     memory: array{max: int, additional: int},
     *     disks: array<string, array{storage: string|null, max: int|null, additional: int|null}>,
     * }
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function availableResources(): array
    {
        $resources = $this->resources();
        $running = ($this->status()['status'] ?? null) === 'running';
        $node = new NodeResource($this->client, $this->node);
        $nodeStatus = $node->status();
        $storages = $node->storages('images');

        $cpuInfo = is_array($nodeStatus['cpuinfo'] ?? null) ? $nodeStatus['cpuinfo'] : [];
        $nodeMemory = is_array($nodeStatus['memory'] ?? null) ? $nodeStatus['memory'] : [];
        $maxCpus = ResourceData::integer($cpuInfo['cpus'] ?? 0);
        $memory = $resources['memory']['size'];
        $maxMemory = ResourceData::mebibytes($nodeMemory['free'] ?? 0) + ($running ? $memory : 0);
        $disks = [];

        foreach ($resources['disks'] as $key => $disk) {
            $available = $disk['storage'] !== null ? ($storages[$disk['storage']]['available'] ?? null) : null;

            $disks[$key] = [
                'storage' => $disk['storage'],
                'max' => $available === null ? null : $disk['size'] + $available,
                'additional' => $available,
            ];
        }

        return [
            'cpu' => [
                'max' => $maxCpus,
                'additional' => max(0, $maxCpus - $resources['cpu']['sockets'] * $resources['cpu']['cores']),
            ],
            'memory' => ['max' => $maxMemory, 'additional' => max(0, $maxMemory - $memory)],
            'disks' => $disks,
        ];
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
     * Destroy the VM and its disks. Proxmox refuses to delete a running VM; stop it first.
     *
     * With $purge, the VM is also removed from backup jobs, replication, and HA.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function delete(bool $purge = true): Task
    {
        return new Task($this->client, $this->node, ResponseData::taskId(
            $this->client->delete(rtrim($this->path(''), '/'), array_filter([
                'purge' => $purge ? 1 : null,
                'destroy-unreferenced-disks' => 1,
            ], is_int(...))),
        ));
    }

    /**
     * Set the CPU cores per socket, keeping the current socket count.
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function setCores(int $cores): Task
    {
        return $this->setCpu($cores);
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
