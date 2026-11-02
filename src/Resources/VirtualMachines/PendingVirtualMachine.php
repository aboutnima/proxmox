<?php

declare(strict_types=1);

namespace Proxmox\Resources\VirtualMachines;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\TaskFailedException;
use Proxmox\Exceptions\TaskTimeoutException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Support\Identifier;
use SensitiveParameter;

/**
 * A fluent VM definition. Values are validated when create() is called.
 *
 * @implements Arrayable<string, mixed>
 */
class PendingVirtualMachine implements Arrayable
{
    use Conditionable;

    /**
     * @var array<string, mixed>
     */
    protected array $attributes;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(protected readonly VirtualMachines $virtualMachines, int|string $vmid)
    {
        $this->attributes = ['vmid' => Identifier::vmid($vmid)];
    }

    public function name(string $name): static
    {
        return $this->set('name', $name);
    }

    public function cores(int $cores): static
    {
        return $this->set('cores', $cores);
    }

    public function sockets(int $sockets): static
    {
        return $this->set('sockets', $sockets);
    }

    public function memory(int $megabytes): static
    {
        return $this->set('memory', $megabytes);
    }

    /**
     * Size of the scsi0 boot disk in GiB.
     */
    public function disk(int $gigabytes): static
    {
        return $this->set('disk', $gigabytes);
    }

    public function storage(string $storage): static
    {
        return $this->set('storage', $storage);
    }

    /**
     * Import a QCOW2 volume such as "local:import/ubuntu.qcow2" as the boot disk.
     */
    public function image(string $volume): static
    {
        return $this->set('image', $volume);
    }

    public function bridge(string $bridge): static
    {
        return $this->set('bridge', $bridge);
    }

    /**
     * Assign a static IPv4 address through Cloud-Init.
     */
    public function ip(string $address, ?int $prefix = null, ?string $gateway = null): static
    {
        unset($this->attributes['prefix'], $this->attributes['gateway']);

        if ($prefix !== null) {
            $this->set('prefix', $prefix);
        }

        if ($gateway !== null) {
            $this->set('gateway', $gateway);
        }

        return $this->set('ip', $address);
    }

    public function dhcp(): static
    {
        unset($this->attributes['prefix'], $this->attributes['gateway']);

        return $this->set('ip', 'dhcp');
    }

    public function user(string $user): static
    {
        return $this->set('user', $user);
    }

    public function password(#[SensitiveParameter] string $password): static
    {
        return $this->set('password', $password);
    }

    public function sshKey(string ...$keys): static
    {
        return $this->set('ssh_key', implode("\n", $keys));
    }

    /**
     * Start the VM after creation. Not supported together with image and disk.
     */
    public function start(bool $start = true): static
    {
        return $this->set('start', $start);
    }

    /**
     * Merge native Proxmox QEMU parameters such as "ostype" or "onboot".
     *
     * @param  array<string, mixed>  $parameters
     */
    public function with(array $parameters): static
    {
        $this->attributes = array_replace($this->attributes, $parameters);

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    /**
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws TaskFailedException
     * @throws TaskTimeoutException
     * @throws UnexpectedResponseException
     */
    public function create(int $importTimeout = 300): Task
    {
        return $this->virtualMachines->create($this, $importTimeout);
    }

    protected function set(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;

        return $this;
    }
}
