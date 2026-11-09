<?php

declare(strict_types=1);

namespace Proxmox\Resources\Nodes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\TaskFailedException;
use Proxmox\Exceptions\TaskTimeoutException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Proxmox;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Resources\VirtualMachines\VirtualMachine;
use Proxmox\Resources\VirtualMachines\VirtualMachineCreation;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResourceData;
use Proxmox\Support\ResponseData;

/**
 * A live node API context, selected by its Proxmox name without fetching data.
 */
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
     * Fetch the node capacity, live usage, and the CPU and memory allocated to its VMs.
     *
     * Memory is in MiB. "allocated" sums all non-template VMs, so it can exceed the total when overcommitted.
     *
     * @return array{
     *     cpu: array{cores: int, percent: float, allocated: int},
     *     memory: array{total: int, used: int, free: int, allocated: int},
     * }
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function resources(): array
    {
        $status = $this->status();
        $cpu = is_array($status['cpuinfo'] ?? null) ? $status['cpuinfo'] : [];
        $memory = is_array($status['memory'] ?? null) ? $status['memory'] : [];
        $allocatedCpus = 0;
        $allocatedMemory = 0;

        foreach ($this->virtualMachines() as $vm) {
            if (ResourceData::integer($vm['template'] ?? 0) === 1) {
                continue;
            }

            $allocatedCpus += ResourceData::integer($vm['cpus'] ?? 0);
            $allocatedMemory += ResourceData::integer($vm['maxmem'] ?? 0);
        }

        return [
            'cpu' => [
                'cores' => ResourceData::integer($cpu['cpus'] ?? 0),
                'percent' => ResourceData::percent($status['cpu'] ?? 0),
                'allocated' => $allocatedCpus,
            ],
            'memory' => [
                'total' => ResourceData::mebibytes($memory['total'] ?? 0),
                'used' => ResourceData::mebibytes($memory['used'] ?? 0),
                'free' => ResourceData::mebibytes($memory['free'] ?? 0),
                'allocated' => ResourceData::mebibytes($allocatedMemory),
            ],
        ];
    }

    /**
     * Fetch enabled storages keyed by name, with sizes in GiB rounded down.
     *
     * Pass a content type such as "images" to list only storages that can hold VM disks.
     *
     * @return array<string, array{type: string|null, content: list<string>, shared: bool, active: bool, total: int, used: int, available: int}>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function storages(?string $content = null): array
    {
        $query = ['enabled' => 1];

        if ($content !== null) {
            $query['content'] = Identifier::name($content, 'content');
        }

        $storages = [];

        foreach (ResponseData::list($this->client->get('nodes/'.rawurlencode($this->name).'/storage', $query)) as $storage) {
            if (! is_string($storage['storage'] ?? null) || $storage['storage'] === '') {
                throw UnexpectedResponseException::expectedStorage();
            }

            $storages[$storage['storage']] = [
                'type' => is_string($storage['type'] ?? null) ? $storage['type'] : null,
                'content' => is_string($storage['content'] ?? null) && $storage['content'] !== ''
                    ? explode(',', $storage['content'])
                    : [],
                'shared' => ResourceData::integer($storage['shared'] ?? 0) === 1,
                'active' => ResourceData::integer($storage['active'] ?? 0) === 1,
                'total' => ResourceData::gibibytes($storage['total'] ?? 0),
                'used' => ResourceData::gibibytes($storage['used'] ?? 0),
                'available' => ResourceData::gibibytes($storage['avail'] ?? 0),
            ];
        }

        return $storages;
    }

    /**
     * Fetch the node's QEMU virtual machines as plain API data.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function virtualMachines(): array
    {
        return ResponseData::list($this->client->get('nodes/'.rawurlencode($this->name).'/qemu'));
    }

    /**
     * Create a VM using readable fields or native Proxmox parameters.
     * Memory is in MiB and disk size is in GiB.
     *
     * @param  array{
     *     vmid: int|string,
     *     name?: string,
     *     sockets?: int,
     *     cores?: int,
     *     memory?: int,
     *     disk?: int,
     *     storage?: string,
     *     image?: string,
     *     bridge?: string,
     *     ip?: string,
     *     prefix?: int,
     *     gateway?: string,
     *     user?: string,
     *     password?: string,
     *     ssh_key?: string,
     *     start?: bool|int,
     *     ostype?: string,
     *     onboot?: bool|int,
     *     boot?: string,
     *     scsihw?: string,
     *     scsi0?: string,
     *     net0?: string,
     *     ide2?: string,
     *     ipconfig0?: string,
     *     ciuser?: string,
     *     cipassword?: string,
     *     sshkeys?: string,
     *     serial0?: string,
     *     vga?: string,
     *     ...
     * }  $configuration
     *
     * @phpstan-param array<string, mixed> $configuration
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws TaskFailedException
     * @throws TaskTimeoutException
     * @throws UnexpectedResponseException
     */
    public function createVirtualMachine(array $configuration, int $importTimeout = 300): Task
    {
        return (new VirtualMachineCreation($this->client, $this->name))->create($configuration, $importTimeout);
    }

    /**
     * Select a VM without fetching runtime data.
     *
     * @throws InvalidArgumentException
     */
    public function virtualMachine(int|string $vmid): VirtualMachine
    {
        return new VirtualMachine($this->client, $this->name, $vmid);
    }

    /**
     * List QCOW2 and RAW sources on storage with the "import" content type enabled.
     *
     * @return list<array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function images(string $storage): array
    {
        $storage = Identifier::name($storage, 'storage');
        $images = ResponseData::list($this->client->get(
            'nodes/'.rawurlencode($this->name).'/storage/'.$storage.'/content',
            ['content' => 'import'],
        ));
        $sources = [];

        foreach ($images as $image) {
            if (! is_string($image['format'] ?? null) || ! is_string($image['volid'] ?? null) || $image['volid'] === '') {
                throw UnexpectedResponseException::expectedImage();
            }

            if (in_array($image['format'], ['qcow2', 'raw'], true)) {
                $sources[] = $image;
            }
        }

        return $sources;
    }

    /**
     * @throws InvalidArgumentException
     */
    public function task(string $upid): Task
    {
        return new Task($this->client, $this->name, $upid);
    }
}
