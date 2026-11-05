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
use Proxmox\Resources\Tasks\Task;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResourceData;
use Proxmox\Support\ResponseData;

/**
 * A VM's fetched runtime data and node-scoped management operations.
 *
 * @implements Arrayable<array-key, mixed>
 *
 * @phpstan-consistent-constructor
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
    public static function make(VirtualMachineModel $record): static
    {
        if (! $record->exists || $record->trashed()) {
            throw new InvalidArgumentException('Pass a saved, non-deleted virtual machine inventory record.');
        }

        $node = $record->node
            ?? throw new InvalidConfigurationException("Virtual machine [{$record->vmid}] has no node in the inventory.");

        if ($node->cluster_id !== $record->cluster_id) {
            throw new InvalidConfigurationException("Node [{$node->name}] does not belong to cluster [{$record->cluster_id}].");
        }

        return new static($node->cluster->client(), $node->name, $record->vmid);
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
        return $this->agent('get-osinfo');
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
        $interfaces = $this->agent('network-get-interfaces');

        /** @var list<string>|null $ipv4 */
        $ipv4 = null;
        /** @var list<string>|null $ipv6 */
        $ipv6 = null;

        if ($interfaces !== null) {
            $ipv4 = [];
            $ipv6 = [];

            foreach ($interfaces as $interface) {
                if (! is_array($interface) || ! is_array($interface['ip-addresses'] ?? null)) {
                    continue;
                }

                foreach ($interface['ip-addresses'] as $address) {
                    $ipAddress = is_array($address) ? ($address['ip-address'] ?? null) : null;

                    if (is_string($ipAddress)
                        && filter_var($ipAddress, FILTER_VALIDATE_IP) !== false
                        && ! self::isLoopbackOrLinkLocalAddress($ipAddress)) {
                        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                            $ipv4[] = $ipAddress;
                        } elseif (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                            $ipv6[] = $ipAddress;
                        }
                    }
                }
            }

            $ipv4 = array_values(array_unique($ipv4));
            $ipv6 = array_values(array_unique($ipv6));
        }

        return ['ipv4' => $ipv4, 'ipv6' => $ipv6];
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

    private static function isLoopbackOrLinkLocalAddress(string $address): bool
    {
        $packed = inet_pton($address);

        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $firstOctet = ord($packed[0]);

            return $firstOctet === 127
                || ($firstOctet === 169 && ord($packed[1]) === 254);
        }

        return $packed === str_repeat("\0", 15)."\1"
            || (ord($packed[0]) === 0xFE && (ord($packed[1]) & 0xC0) === 0x80);
    }

    /**
     * Fetch VM process uptime in seconds and estimate its startup Unix timestamp.
     *
     * @return array{uptime: int, started_at: int|null}
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function startup(): array
    {
        $status = $this->status();

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
     * Fetch the free resources inside the VM's own allocation; node and storage capacity is never read.
     *
     * CPU is a percentage of the VM's vCPUs and memory is in MiB. Memory uses the guest-reported free
     * memory when ballooning provides it. Disks (GiB, rounded down) compare each disk's size with the
     * space its volume occupies on storage; "used" is null when the storage does not report it.
     * Filesystems (GiB) come from the QEMU guest agent and are null when the VM is not running, the
     * agent is not enabled, or the agent does not respond.
     *
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
    public function availableResources(): array
    {
        $status = $this->status();
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
            'filesystems' => $running && ResourceData::integer($status['agent'] ?? 0) === 1 ? $this->filesystems() : null,
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
        return array_map(static function (array $point): array {
            $vcpus = ResourceData::nullableNumber($point['maxcpu'] ?? null, 0);
            $cpu = ResourceData::nullableNumber($point['cpu'] ?? null, 6);

            return [
                'time' => ResourceData::integer($point['time'] ?? 0),
                'vcpus' => $vcpus === null ? null : (int) $vcpus,
                'percent' => $cpu === null ? null : ResourceData::percent($cpu),
            ];
        }, $this->history($timeframe, $consolidation));
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
        return array_map(static fn (array $point): array => [
            'time' => ResourceData::integer($point['time'] ?? 0),
            'total' => ResourceData::nullableNumber($point['maxmem'] ?? null) === null ? null : ResourceData::mebibytes($point['maxmem']),
            'used' => ResourceData::nullableNumber($point['mem'] ?? null) === null ? null : ResourceData::mebibytes($point['mem']),
        ], $this->history($timeframe, $consolidation));
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
        return array_map(static fn (array $point): array => [
            'time' => ResourceData::integer($point['time'] ?? 0),
            'in' => ResourceData::nullableNumber($point['netin'] ?? null),
            'out' => ResourceData::nullableNumber($point['netout'] ?? null),
        ], $this->history($timeframe, $consolidation));
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
        return array_map(static fn (array $point): array => [
            'time' => ResourceData::integer($point['time'] ?? 0),
            'read' => ResourceData::nullableNumber($point['diskread'] ?? null),
            'write' => ResourceData::nullableNumber($point['diskwrite'] ?? null),
        ], $this->history($timeframe, $consolidation));
    }

    /**
     * @return list<array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    private function history(string $timeframe, string $consolidation): array
    {
        if (! in_array($timeframe, ['hour', 'day', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException('timeframe must be hour, day, week, month, or year.');
        }

        if (! in_array($consolidation, ['average', 'max'], true)) {
            throw new InvalidArgumentException('consolidation must be average or max.');
        }

        $points = [];

        foreach (ResponseData::list($this->client->get($this->path('rrddata'), [
            'timeframe' => $timeframe,
            'cf' => strtoupper($consolidation),
        ])) as $point) {
            if (! is_int($point['time'] ?? null)) {
                continue;
            }

            $points[] = $point;
        }

        return $points;
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

    /**
     * Run a read-only guest agent command and return its "result", or null when the agent is not running.
     *
     * @return array<array-key, mixed>|null
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    private function agent(string $command): ?array
    {
        try {
            $response = ResponseData::attributes($this->client->get($this->path('agent/'.$command)));
        } catch (RequestException $exception) {
            // Proxmox answers 500 when the guest agent is not running inside the VM.
            if ($exception->response->serverError()) {
                return null;
            }

            throw $exception;
        }

        return is_array($response['result'] ?? null) ? $response['result'] : [];
    }

    /**
     * @return list<array{mountpoint: string, type: string|null, total: int, used: int, available: int}>|null
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    private function filesystems(): ?array
    {
        $result = $this->agent('get-fsinfo');

        if ($result === null) {
            return null;
        }

        $filesystems = [];

        foreach ($result as $filesystem) {
            if (! is_array($filesystem) || ! is_string($filesystem['mountpoint'] ?? null) || ! array_key_exists('total-bytes', $filesystem)) {
                continue;
            }

            $total = ResourceData::integer($filesystem['total-bytes']);
            $used = min($total, ResourceData::integer($filesystem['used-bytes'] ?? 0));

            $filesystems[] = [
                'mountpoint' => $filesystem['mountpoint'],
                'type' => is_string($filesystem['type'] ?? null) ? $filesystem['type'] : null,
                'total' => ResourceData::gibibytes($total),
                'used' => ResourceData::gibibytes($used),
                'available' => ResourceData::gibibytes($total - $used),
            ];
        }

        return $filesystems;
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
