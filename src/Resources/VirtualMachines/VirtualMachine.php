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
use Proxmox\Support\ResourceData;
use Proxmox\Support\ResponseData;

/**
 * A live VM API resource with an optional in-memory status snapshot.
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
    private array $snapshot = [];

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private readonly Proxmox $client, string $node, int|string $vmid)
    {
        $this->node = Identifier::node($node);
        $this->vmid = Identifier::vmid($vmid);
    }

    /**
     * Fetch the current runtime status without changing the in-memory snapshot.
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
     * Fetch operating system information reported by the QEMU guest agent.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function osInfo(): ?array
    {
        return $this->guestAgent()->osInfo();
    }

    /**
     * Fetch guest IPv4 and IPv6 addresses, excluding loopback and link-local addresses.
     *
     * @return array{ipv4: list<string>|null, ipv6: list<string>|null}
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function networkInfo(): array
    {
        return $this->guestAgent()->networkInfo();
    }

    /**
     * Return the configured Cloud-Init username or a default for known Linux and Windows OS types.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function username(): ?string
    {
        $config = $this->config();
        $configuredUsername = $config['ciuser'] ?? null;
        $username = is_string($configuredUsername) && trim($configuredUsername) !== ''
            ? trim($configuredUsername)
            : null;

        if ($username === null && is_string($config['ostype'] ?? null)) {
            $osType = strtolower($config['ostype']);
            $username = match (true) {
                str_starts_with($osType, 'l') => 'root',
                str_starts_with($osType, 'w') => 'Administrator',
                default => null,
            };
        }

        return $username;
    }

    /**
     * Fetch VM process uptime in seconds and estimate its startup Unix timestamp.
     *
     * Pass an existing status to reuse it without sending another request.
     *
     * @param  array<array-key, mixed>|null  $status
     * @return array{uptime: int, started_at: int|null}
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function startup(?array $status = null): array
    {
        $status ??= $this->status();

        if (($status['status'] ?? null) === 'stopped') {
            return ['uptime' => 0, 'started_at' => null];
        }

        $uptime = $status['uptime'] ?? null;

        if (($status['status'] ?? null) !== 'running' || ! is_int($uptime) || $uptime < 0) {
            throw UnexpectedResponseException::expectedVirtualMachineStartup();
        }

        return ['uptime' => $uptime, 'started_at' => now()->getTimestamp() - $uptime];
    }

    /**
     * Replace the in-memory snapshot with the latest runtime status.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function refresh(): static
    {
        $this->snapshot = $this->status();

        return $this;
    }

    /**
     * Return the in-memory status snapshot without sending a request.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->snapshot;
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
     * Pass an existing status to reuse it without sending another request.
     *
     * @param  array<array-key, mixed>|null  $status
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
    public function usage(?array $status = null): array
    {
        $status ??= $this->status();
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
    public function allocatedResources(): array
    {
        $config = $this->currentConfig();
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
     * @deprecated Use allocatedResources() instead.
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
        return $this->allocatedResources();
    }

    /**
     * Fetch the free resources inside the VM's own allocation; node and storage capacity is never read.
     *
     * CPU is a percentage of the VM's vCPUs and memory is in MiB. Memory uses the guest-reported free
     * memory when ballooning provides it. Disks (GiB, rounded down) compare each disk's size with the
     * space its volume occupies on storage; "used" is null when the storage does not report it.
     * Filesystems (GiB) come from the QEMU guest agent and are null when the VM is not running, the
     * agent is not enabled, or the agent does not respond.
     *
     * Pass an existing status to avoid another status request. Disk and guest-agent requests are still made as needed.
     *
     * @param  array<array-key, mixed>|null  $status
     * @return array{
     *     cpu: array{vcpus: int, used: float, available: float},
     *     memory: array{total: int, used: int, available: int},
     *     disks: array<string, array{storage: string|null, total: int, used: int|null, available: int|null}>,
     *     filesystems: list<array{mountpoint: string, type: string|null, total: int, used: int, available: int}>|null,
     * }
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function availableResources(?array $status = null): array
    {
        $status ??= $this->status();
        $running = ($status['status'] ?? null) === 'running';
        $cpu = $running ? min(100.0, ResourceData::percent($status['cpu'] ?? 0)) : 0.0;
        $total = ResourceData::mebibytes($status['maxmem'] ?? 0);
        $available = ! $running ? $total : (array_key_exists('freemem', $status)
            ? ResourceData::mebibytes($status['freemem'])
            : max(0, $total - ResourceData::mebibytes($status['mem'] ?? 0)));
        $available = min($total, $available);

        return [
            'cpu' => [
                'vcpus' => ResourceData::integer($status['cpus'] ?? 0),
                'used' => $cpu,
                'available' => round(100 - $cpu, 2),
            ],
            'memory' => ['total' => $total, 'used' => $total - $available, 'available' => $available],
            'disks' => $this->diskUsage(),
            'filesystems' => $running && ResourceData::integer($status['agent'] ?? 0) === 1 ? $this->guestAgent()->filesystems() : null,
        ];
    }

    /**
     * Fetch CPU usage over time as a percentage of the VM's vCPUs.
     *
     * Points where Proxmox has no data, such as while the VM was stopped, have null values.
     *
     * @param  'hour'|'day'|'week'|'month'|'year'  $timeframe
     * @param  'average'|'max'  $consolidation
     * @return list<array{time: int, vcpus: int|null, percent: float|null}>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function cpuHistory(string $timeframe = 'hour', string $consolidation = 'average'): array
    {
        return $this->history()->cpu($timeframe, $consolidation);
    }

    /**
     * Fetch memory usage over time in MiB, rounded down.
     *
     * @param  'hour'|'day'|'week'|'month'|'year'  $timeframe
     * @param  'average'|'max'  $consolidation
     * @return list<array{time: int, total: int|null, used: int|null}>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function memoryHistory(string $timeframe = 'hour', string $consolidation = 'average'): array
    {
        return $this->history()->memory($timeframe, $consolidation);
    }

    /**
     * Fetch network traffic over time in bytes per second.
     *
     * @param  'hour'|'day'|'week'|'month'|'year'  $timeframe
     * @param  'average'|'max'  $consolidation
     * @return list<array{time: int, in: float|null, out: float|null}>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function networkHistory(string $timeframe = 'hour', string $consolidation = 'average'): array
    {
        return $this->history()->network($timeframe, $consolidation);
    }

    /**
     * Fetch disk I/O over time in bytes per second.
     *
     * @param  'hour'|'day'|'week'|'month'|'year'  $timeframe
     * @param  'average'|'max'  $consolidation
     * @return list<array{time: int, read: float|null, write: float|null}>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function diskHistory(string $timeframe = 'hour', string $consolidation = 'average'): array
    {
        return $this->history()->disk($timeframe, $consolidation);
    }

    /**
     * Fetch all four usage history series with one request.
     *
     * @param  'hour'|'day'|'week'|'month'|'year'  $timeframe
     * @param  'average'|'max'  $consolidation
     * @return array{
     *     cpu: list<array{time: int, vcpus: int|null, percent: float|null}>,
     *     memory: list<array{time: int, total: int|null, used: int|null}>,
     *     network: list<array{time: int, in: float|null, out: float|null}>,
     *     disk: list<array{time: int, read: float|null, write: float|null}>,
     * }
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function usageHistory(string $timeframe = 'hour', string $consolidation = 'average'): array
    {
        return $this->history()->all($timeframe, $consolidation);
    }

    private function history(): VirtualMachineHistory
    {
        return new VirtualMachineHistory($this->client, $this->path('rrddata'));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    private function currentConfig(): array
    {
        return ResponseData::attributes($this->client->get($this->path('config'), ['current' => 1]));
    }

    /**
     * Compare each disk's size with the space its volume occupies, listing only this VM's volumes.
     *
     * @return array<string, array{storage: string|null, total: int, used: int|null, available: int|null}>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    private function diskUsage(): array
    {
        $volumes = ResourceData::volumes($this->currentConfig());
        $usage = [];

        foreach (array_unique(array_filter(array_column($volumes, 'storage'))) as $storage) {
            $content = ResponseData::list($this->client->get(
                'nodes/'.rawurlencode($this->node).'/storage/'.rawurlencode($storage).'/content',
                ['vmid' => $this->vmid],
            ));

            foreach ($content as $volume) {
                if (is_string($volume['volid'] ?? null) && ResourceData::nullableNumber($volume['used'] ?? null) !== null) {
                    $usage[$volume['volid']] = max(0, ResourceData::integer($volume['used']));
                }
            }
        }

        $disks = [];

        foreach ($volumes as $key => $volume) {
            $used = array_key_exists($volume['volume'], $usage) ? min($volume['bytes'], $usage[$volume['volume']]) : null;

            $disks[$key] = [
                'storage' => $volume['storage'],
                'total' => ResourceData::gibibytes($volume['bytes']),
                'used' => $used === null ? null : ResourceData::gibibytes($used),
                'available' => $used === null ? null : ResourceData::gibibytes($volume['bytes'] - $used),
            ];
        }

        return $disks;
    }

    private function guestAgent(): VirtualMachineGuestAgent
    {
        return new VirtualMachineGuestAgent($this->client, $this->path('agent'));
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
    public function setMemory(int $mebibytes): Task
    {
        if ($mebibytes < 1) {
            throw new InvalidArgumentException('mebibytes must be a positive integer.');
        }

        return $this->configure(['memory' => $mebibytes]);
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
    public function growDisk(string $disk, int $gibibytes): Task
    {
        if (preg_match('/^[a-z]+[0-9]+$/D', $disk) !== 1) {
            throw new InvalidArgumentException('disk must be a disk key such as scsi0 or virtio1.');
        }

        if ($gibibytes < 1) {
            throw new InvalidArgumentException('gibibytes must be a positive integer.');
        }

        return new Task($this->client, $this->node, ResponseData::taskId(
            $this->client->put($this->path('resize'), ['disk' => $disk, 'size' => '+'.$gibibytes.'G']),
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
