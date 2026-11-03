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
 * @method static list<array<array-key, mixed>> nodes()
 * @method static \Proxmox\Resources\Nodes\Node node(string $node)
 * @method static \Proxmox\Resources\Tasks\Task task(string $upid)
 * @method static int nextVirtualMachineId()
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
