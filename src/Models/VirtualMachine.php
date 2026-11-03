<?php

declare(strict_types=1);

namespace Proxmox\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\ProxmoxException;
use Proxmox\Inventory;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Resources\VirtualMachines\VirtualMachine as VirtualMachineResource;
use Throwable;

/**
 * A QEMU virtual machine kept in sync with Proxmox.
 *
 * Creating a record creates the VM on its node, deleting a record stops and
 * destroys the VM, and the management methods run on the live VM. Use
 * withoutProxmox() to change records only, such as for VMs that already exist.
 * New VMs always get the next free VMID of their cluster.
 *
 * The row ID identifies one VM lifecycle: Proxmox reuses VMIDs after a VM is
 * deleted, so a reused VMID gets a new row. The node is current placement.
 *
 * @property int $id
 * @property int $cluster_id
 * @property int|null $node_id
 * @property int $vmid
 * @property string|null $name
 * @property string|null $status
 * @property string|null $owner_type
 * @property int|string|null $owner_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Cluster $cluster
 * @property-read Node|null $node
 * @property-read Model|null $owner
 */
class VirtualMachine extends Model
{
    use SoftDeletes;

    /**
     * Times to request a new VMID when another request takes the same one.
     */
    protected const int CREATE_ATTEMPTS = 3;

    protected $table = 'proxmox_virtual_machines';

    protected $fillable = [
        'cluster_id',
        'node_id',
        'vmid',
        'name',
        'status',
    ];

    /**
     * Seconds to wait for each Proxmox task when deleting a VM.
     */
    protected int $proxmoxTaskTimeout = 300;

    private static bool $syncsWithProxmox = true;

    /**
     * @var array<string, mixed>
     */
    private array $proxmoxConfiguration = [];

    private Node|string|null $pendingNode = null;

    private Cluster|string|null $pendingCluster = null;

    private int $importTimeout = 300;

    private ?Task $creationTask = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cluster_id' => 'integer',
            'node_id' => 'integer',
            'vmid' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (VirtualMachine $vm): void {
            if (self::$syncsWithProxmox) {
                $vm->createOnProxmox();
            }
        });

        static::deleting(function (VirtualMachine $vm): void {
            // A trashed VM was already destroyed on Proxmox when it was soft deleted.
            if (self::$syncsWithProxmox && ! $vm->trashed()) {
                $vm->deleteFromProxmox();
            }
        });
    }

    /**
     * Change records without creating or destroying VMs on Proxmox.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function withoutProxmox(callable $callback): mixed
    {
        $previous = self::$syncsWithProxmox;
        self::$syncsWithProxmox = false;

        try {
            return $callback();
        } finally {
            self::$syncsWithProxmox = $previous;
        }
    }

    /**
     * Fill columns, and for new VMs keep the remaining keys as the Proxmox configuration.
     *
     * "node" accepts a Node model, or a node name together with "cluster" (a Cluster
     * model or name). "import_timeout" sets the seconds allowed for image imports.
     *
     * @param  array<array-key, mixed>  $attributes
     * @return $this
     */
    public function fill(array $attributes): static
    {
        if ($this->exists) {
            return parent::fill($attributes);
        }

        if (array_key_exists('node', $attributes)) {
            $this->pendingNode = $attributes['node'] instanceof Node || is_string($attributes['node'])
                ? $attributes['node']
                : throw new InvalidArgumentException('node must be a Proxmox\Models\Node or a node name.');
        }

        if (array_key_exists('cluster', $attributes)) {
            $this->pendingCluster = $attributes['cluster'] instanceof Cluster || is_string($attributes['cluster'])
                ? $attributes['cluster']
                : throw new InvalidArgumentException('cluster must be a Proxmox\Models\Cluster or a cluster name.');
        }

        if (array_key_exists('import_timeout', $attributes)) {
            $this->importTimeout = is_int($attributes['import_timeout'])
                ? $attributes['import_timeout']
                : throw new InvalidArgumentException('import_timeout must be a number of seconds.');
        }

        $columns = $this->getFillable();

        $this->proxmoxConfiguration = [
            ...$this->proxmoxConfiguration,
            ...Arr::except($attributes, [...array_diff($columns, ['vmid', 'name']), 'node', 'cluster', 'import_timeout']),
        ];

        return parent::fill(Arr::only($attributes, $columns));
    }

    /**
     * @return BelongsTo<Cluster, $this>
     *
     * @throws InvalidConfigurationException
     */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(Inventory::clusterModel(), 'cluster_id');
    }

    /**
     * @return BelongsTo<Node, $this>
     *
     * @throws InvalidConfigurationException
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Inventory::nodeModel(), 'node_id');
    }

    /**
     * The model that owns this VM, such as a user or team.
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The task returned by Proxmox when this record created the VM.
     *
     * With an image and disk, the image import has finished and this is the disk resize task.
     */
    public function creationTask(): ?Task
    {
        return $this->creationTask;
    }

    /**
     * The live VM's management operations, without sending a request.
     *
     * @throws ProxmoxException
     */
    public function api(): VirtualMachineResource
    {
        return new VirtualMachineResource($this->cluster->client(), $this->placement()->name, $this->vmid);
    }

    /**
     * Fetch this VM's runtime data and management methods from Proxmox.
     *
     * @throws ProxmoxException
     */
    public function resource(): VirtualMachineResource
    {
        return $this->api()->refresh();
    }

    /**
     * @throws ProxmoxException
     */
    public function start(): Task
    {
        return $this->api()->start();
    }

    /**
     * @throws ProxmoxException
     */
    public function shutdown(?int $timeout = null): Task
    {
        return $this->api()->shutdown($timeout);
    }

    /**
     * @throws ProxmoxException
     */
    public function restart(?int $timeout = null): Task
    {
        return $this->api()->restart($timeout);
    }

    /**
     * @throws ProxmoxException
     */
    public function pause(): Task
    {
        return $this->api()->pause();
    }

    /**
     * @throws ProxmoxException
     */
    public function hibernate(): Task
    {
        return $this->api()->hibernate();
    }

    /**
     * @throws ProxmoxException
     */
    public function resume(): Task
    {
        return $this->api()->resume();
    }

    /**
     * @throws ProxmoxException
     */
    public function stop(): Task
    {
        return $this->api()->stop();
    }

    /**
     * @throws ProxmoxException
     */
    public function reset(): Task
    {
        return $this->api()->reset();
    }

    /**
     * Set the CPU cores per socket, keeping the current socket count.
     *
     * @throws ProxmoxException
     */
    public function setCores(int $cores): Task
    {
        return $this->api()->setCores($cores);
    }

    /**
     * @throws ProxmoxException
     */
    public function setCpu(int $cores, ?int $sockets = null): Task
    {
        return $this->api()->setCpu($cores, $sockets);
    }

    /**
     * @throws ProxmoxException
     */
    public function setMemory(int $megabytes): Task
    {
        return $this->api()->setMemory($megabytes);
    }

    /**
     * @throws ProxmoxException
     */
    public function growDisk(string $disk, int $gigabytes): Task
    {
        return $this->api()->growDisk($disk, $gigabytes);
    }

    /**
     * @throws Throwable
     */
    protected function createOnProxmox(): void
    {
        $node = $this->provisioningNode();

        if ($this->getAttribute('cluster_id') !== null && $this->cluster_id !== $node->cluster_id) {
            throw new InvalidArgumentException("Node [{$node->name}] does not belong to cluster [{$this->cluster_id}].");
        }

        $this->cluster_id = $node->cluster_id;
        $this->node_id = $node->id;
        $this->setRelation('node', $node);

        if ($this->getAttribute('vmid') !== null) {
            throw new InvalidArgumentException('Do not pass vmid; the next free ID of the cluster is used. Wrap the call in VirtualMachine::withoutProxmox() to record an existing VM.');
        }

        $client = $node->cluster->client();

        for ($attempt = 1; ; $attempt++) {
            $this->vmid = $client->nextVirtualMachineId();

            try {
                $this->creationTask = $client->node($node->name)->virtualMachines()->create(
                    [...$this->proxmoxConfiguration, 'vmid' => $this->vmid],
                    $this->importTimeout,
                );

                return;
            } catch (RequestException $exception) {
                // Another request took the same ID between nextid and create.
                if ($attempt >= self::CREATE_ATTEMPTS || ! str_contains((string) $exception->response->body(), 'already exists')) {
                    unset($this->vmid);

                    throw $exception;
                }
            }
        }
    }

    /**
     * Stop the VM when needed, then destroy it. A VM that no longer exists counts as deleted.
     *
     * @throws Throwable
     */
    protected function deleteFromProxmox(): void
    {
        $api = $this->api();

        try {
            $status = $api->status();
        } catch (RequestException $exception) {
            if ($this->isMissing($exception)) {
                return;
            }

            throw $exception;
        }

        if (($status['status'] ?? null) !== 'stopped') {
            $api->stop()->wait($this->proxmoxTaskTimeout);
        }

        $api->delete()->wait($this->proxmoxTaskTimeout);
    }

    /**
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    private function provisioningNode(): Node
    {
        if ($this->pendingNode instanceof Node) {
            return $this->pendingNode;
        }

        if ($this->pendingNode === null) {
            return $this->getAttribute('node_id') === null
                ? throw new InvalidArgumentException('Pass the node to create the VM on, such as [\'node\' => $node].')
                : $this->placement();
        }

        $cluster = match (true) {
            $this->pendingCluster instanceof Cluster => $this->pendingCluster,
            is_string($this->pendingCluster) => Inventory::clusterModel()::query()->where('name', $this->pendingCluster)->first()
                ?? throw InvalidConfigurationException::clusterNotFound($this->pendingCluster),
            $this->getAttribute('cluster_id') !== null => Inventory::clusterModel()::query()->findOrFail($this->cluster_id),
            default => throw new InvalidArgumentException('Pass the cluster together with a node name, such as [\'cluster\' => \'default\', \'node\' => \'pve1\'].'),
        };

        return $cluster->nodes()->firstOrCreate(['name' => $this->pendingNode]);
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function placement(): Node
    {
        return $this->node ?? throw new InvalidConfigurationException("Virtual machine [{$this->vmid}] has no node in the inventory.");
    }

    /**
     * Proxmox answers 500 "Configuration file ... does not exist" for unknown VMs.
     */
    private function isMissing(RequestException $exception): bool
    {
        return $exception->response->status() === 404
            || ($exception->response->status() === 500 && str_contains($exception->response->body(), 'does not exist'));
    }
}
