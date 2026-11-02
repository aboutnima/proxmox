<?php

declare(strict_types=1);

namespace Proxmox\Resources\VirtualMachines;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\TaskFailedException;
use Proxmox\Exceptions\TaskTimeoutException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Proxmox;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResourceQuery;
use Proxmox\Support\ResponseData;

/**
 * QEMU virtual machines on a single node.
 */
class VirtualMachines extends ResourceQuery
{
    public readonly string $node;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private readonly Proxmox $client, string $node)
    {
        $this->node = Identifier::node($node);
    }

    /**
     * Start a fluent VM definition that is sent with create().
     *
     * @throws InvalidArgumentException
     */
    public function make(int|string $vmid): PendingVirtualMachine
    {
        return new PendingVirtualMachine($this, $vmid);
    }

    /**
     * Create a virtual machine using readable fields or native API parameters.
     *
     * With "image" and "disk", the import task is awaited and the returned task
     * is the follow-up disk resize.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $configuration
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws TaskFailedException
     * @throws TaskTimeoutException
     * @throws UnexpectedResponseException
     */
    public function create(array|Arrayable $configuration, int $importTimeout = 300): Task
    {
        $configuration = is_array($configuration) ? $configuration : $configuration->toArray();
        $parameters = VirtualMachineConfiguration::toApiParameters($configuration);
        $disk = $configuration['disk'] ?? null;

        if (! array_key_exists('image', $configuration) || ! is_int($disk)) {
            return $this->submit($parameters);
        }

        $vmid = Identifier::vmid($configuration['vmid'] ?? null);

        if ($importTimeout < 1) {
            throw new InvalidArgumentException('importTimeout must be a positive number of seconds.');
        }

        if (! empty($configuration['start'])) {
            throw new InvalidArgumentException('Do not use start with image and disk; start the VM after its resize task succeeds.');
        }

        $this->submit($parameters)->wait($importTimeout);

        return (new VirtualMachine($this->client, $this->node, $vmid))->resize('scsi0', $disk.'G');
    }

    protected function fetch(): Collection
    {
        return ResponseData::list($this->client->get('nodes/'.rawurlencode($this->node).'/qemu'));
    }

    /**
     * @param  array<string, mixed>  $parameters
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    private function submit(array $parameters): Task
    {
        return new Task($this->client, $this->node, ResponseData::taskId(
            $this->client->post('nodes/'.rawurlencode($this->node).'/qemu', $parameters),
        ));
    }
}
