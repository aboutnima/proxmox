<?php

declare(strict_types=1);

namespace Proxmox\Resources\VirtualMachines;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\TaskFailedException;
use Proxmox\Exceptions\TaskTimeoutException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Proxmox;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResponseData;

/**
 * QEMU creation and cloud-image import on a single node.
 *
 * @internal
 */
readonly class VirtualMachineCreation
{
    public string $node;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private Proxmox $client, string $node)
    {
        $this->node = Identifier::node($node);
    }

    /**
     * Create a VM using readable fields or native API parameters.
     *
     * Memory is in MiB and disk is in GiB. Additional native QEMU keys are
     * passed through. With image and disk, await import and return the resize task.
     *
     * @param  array<string, mixed>  $configuration
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws TaskFailedException
     * @throws TaskTimeoutException
     * @throws UnexpectedResponseException
     */
    public function create(array $configuration, int $importTimeout = 300): Task
    {
        $vmid = Identifier::vmid(Arr::get($configuration, 'vmid'));
        $parameters = VirtualMachineConfiguration::toApiParameters($configuration);
        $disk = $configuration['disk'] ?? null;

        if (! array_key_exists('image', $configuration) || ! is_int($disk)) {
            return $this->submit($parameters);
        }

        if ($importTimeout < 1) {
            throw new InvalidArgumentException('importTimeout must be a positive number of seconds.');
        }

        if (! empty($configuration['start'])) {
            throw new InvalidArgumentException('Do not use start with image and disk; start the VM after its resize task succeeds.');
        }

        $this->submit($parameters)->wait($importTimeout);

        return new Task($this->client, $this->node, ResponseData::taskId(
            $this->client->put('nodes/'.rawurlencode($this->node).'/qemu/'.$vmid.'/resize', ['disk' => 'scsi0', 'size' => $disk.'G']),
        ));
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
