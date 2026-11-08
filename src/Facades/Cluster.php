<?php

declare(strict_types=1);

namespace Proxmox\Facades;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Facade;
use Proxmox\Proxmox;
use Proxmox\ProxmoxManager;
use Proxmox\Resources\Nodes\Node;
use Proxmox\Resources\Tasks\Task;

/**
 * @method static \Proxmox\Proxmox connection(string|null $name = null)
 * @method static \Proxmox\Proxmox connectUsing(string $name, array<string, mixed> $config)
 * @method static string getDefaultConnection()
 * @method static void setDefaultConnection(string $name)
 * @method static string getName()
 * @method static Response request(string $method, string $endpoint, array<string, mixed> $options = [])
 * @method static Response get(string $endpoint, array<string, mixed> $query = [])
 * @method static Response post(string $endpoint, array<string, mixed> $data = [])
 * @method static Response put(string $endpoint, array<string, mixed> $data = [])
 * @method static Response delete(string $endpoint, array<string, mixed> $query = [])
 * @method static list<array<array-key, mixed>> nodes()
 * @method static Node node(string $node)
 * @method static Task task(string $upid)
 * @method static int nextVirtualMachineId()
 *
 * @see ProxmoxManager
 * @see Proxmox
 */
class Cluster extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ProxmoxManager::class;
    }
}
