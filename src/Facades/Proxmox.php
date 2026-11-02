<?php

declare(strict_types=1);

namespace Proxmox\Facades;

use Illuminate\Support\Facades\Facade;
use Proxmox\ProxmoxManager;

/**
 * @method static \Proxmox\Proxmox connection(string|null $name = null)
 * @method static string getDefaultConnection()
 * @method static void setDefaultConnection(string $name)
 * @method static void purge(string|null $name = null)
 * @method static array<string, \Proxmox\Proxmox> getConnections()
 * @method static string getName()
 * @method static \Illuminate\Http\Client\Response request(string $method, string $endpoint, array<string, mixed> $options = [])
 * @method static \Illuminate\Http\Client\Response get(string $endpoint, array<string, mixed> $query = [])
 * @method static \Illuminate\Http\Client\Response post(string $endpoint, array<string, mixed> $data = [])
 * @method static \Illuminate\Http\Client\Response put(string $endpoint, array<string, mixed> $data = [])
 * @method static \Illuminate\Http\Client\Response delete(string $endpoint, array<string, mixed> $query = [])
 * @method static \Proxmox\Resources\Nodes\Nodes|\Proxmox\Resources\Nodes\Node nodes(string|null $node = null)
 * @method static \Proxmox\Resources\Nodes\Node node(string $node)
 * @method static \Proxmox\Resources\Tasks\Task task(string $upid)
 * @method static int nextVirtualMachineId()
 * @method static \Illuminate\Support\Collection<int, array<array-key, mixed>> getNodes()
 * @method static array<array-key, mixed> getNode(string $node)
 * @method static \Illuminate\Support\Collection<int, array<array-key, mixed>> getVirtualMachines(string $node)
 * @method static array<array-key, mixed> getVirtualMachine(string $node, int|string $vmid)
 * @method static \Illuminate\Support\Collection<int, array<array-key, mixed>> getImages(string $node, string $storage)
 * @method static \Proxmox\Resources\Tasks\Task createVirtualMachine(string $node, array<string, mixed>|\Illuminate\Contracts\Support\Arrayable<string, mixed> $configuration, int $importTimeout = 300)
 *
 * @see ProxmoxManager
 * @see \Proxmox\Proxmox
 */
class Proxmox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProxmoxManager::class;
    }
}
