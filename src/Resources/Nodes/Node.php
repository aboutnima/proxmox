<?php

declare(strict_types=1);

namespace Proxmox\Resources\Nodes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Proxmox;
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
     * Access VM listing and creation operations without sending a request.
     */
    public function virtualMachines(): VirtualMachines
    {
        return new VirtualMachines($this->client, $this->name);
    }

    /**
     * Fetch a VM's runtime data and retain its management methods.
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function virtualMachine(int|string $vmid): VirtualMachine
    {
        return (new VirtualMachine($this->client, $this->name, $vmid))->refresh();
    }

    /**
     * List QCOW2 sources on storage with the "import" content type enabled.
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

            if ($image['format'] === 'qcow2') {
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
