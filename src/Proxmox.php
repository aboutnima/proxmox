<?php

declare(strict_types=1);

namespace Proxmox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use LogicException;
use Proxmox\Resources\Node;
use Proxmox\Resources\Nodes;
use Proxmox\Support\VirtualMachineConfiguration;
use RuntimeException;
use UnexpectedValueException;

readonly class Proxmox
{
    public function __construct(
        private string $baseUrl,
        private string $apiTokenId,
        private string $apiTokenSecret,
        private int $timeout = 30,
        private bool $verifySsl = true,
    ) {}

    /**
     * Send a request to a Proxmox API endpoint.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function request(string $method, string $endpoint, array $options = []): Response
    {
        $request = $this->pendingRequest();

        if (array_key_exists('form_params', $options)) {
            $request->asForm();
        }

        return $request->send(
            strtoupper($method),
            $this->normalizeEndpoint($endpoint),
            $options,
        );
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function get(string $endpoint, array $query = []): Response
    {
        return $this->pendingRequest()->get($this->normalizeEndpoint($endpoint), $query);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function post(string $endpoint, array $data = []): Response
    {
        return $this->pendingRequest()
            ->asForm()
            ->post($this->normalizeEndpoint($endpoint), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function put(string $endpoint, array $data = []): Response
    {
        return $this->pendingRequest()
            ->asForm()
            ->put($this->normalizeEndpoint($endpoint), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function delete(string $endpoint, array $data = []): Response
    {
        return $this->pendingRequest()
            ->asForm()
            ->delete($this->normalizeEndpoint($endpoint), $data);
    }

    /**
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function getNodes(): Collection
    {
        return $this->collection($this->get('nodes'));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function getNode(string $node): array
    {
        return $this->attributes($this->get('nodes/'.$this->normalizeNodeName($node).'/status'));
    }

    /**
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function getVirtualMachines(string $node): Collection
    {
        return $this->collection($this->get('nodes/'.$this->normalizeNodeName($node).'/qemu'));
    }

    /**
     * List QCOW2 source images on storage with the "import" content type enabled.
     *
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function getImages(string $node, string $storage): Collection
    {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $storage) !== 1) {
            throw new InvalidArgumentException('Proxmox storage must be a valid storage identifier.');
        }

        return $this->collection($this->get(
            'nodes/'.$this->normalizeNodeName($node).'/storage/'.$storage.'/content',
            ['content' => 'import'],
        ))->filter(function (array $image): bool {
            if (! is_string($image['format'] ?? null) || ! is_string($image['volid'] ?? null) || $image['volid'] === '') {
                throw new UnexpectedValueException('Expected format and volid strings in the Proxmox image listing.');
            }

            return $image['format'] === 'qcow2';
        })->values();
    }

    /**
     * Create a QEMU virtual machine using readable fields or native API parameters.
     * With image and disk, wait for import before returning the resize task ID.
     *
     * @param  array<string, mixed>  $configuration
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function createVirtualMachine(string $node, array $configuration, int $importTimeout = 300): string
    {
        $node = $this->normalizeNodeName($node);
        $parameters = VirtualMachineConfiguration::toApiParameters($configuration);
        $disk = $configuration['disk'] ?? null;
        $resizeVmid = null;

        if (array_key_exists('image', $configuration) && is_int($disk)) {
            $vmid = $configuration['vmid'] ?? null;

            if (! is_int($vmid) && ! is_string($vmid)) {
                throw new InvalidArgumentException('Proxmox VM ID must be a positive integer.');
            }

            $resizeVmid = $this->normalizeVirtualMachineId($vmid);

            if ($importTimeout < 1) {
                throw new InvalidArgumentException('importTimeout must be a positive number of seconds.');
            }

            if (! empty($configuration['start'])) {
                throw new InvalidArgumentException('Do not use start with image and disk; start the VM after its resize task succeeds.');
            }
        }

        $task = $this->taskId($this->post('nodes/'.$node.'/qemu', $parameters));

        if ($resizeVmid !== null) {
            $this->waitForTask($node, $task, $importTimeout);

            return $this->taskId($this->put('nodes/'.$node.'/qemu/'.$resizeVmid.'/resize', [
                'disk' => 'scsi0',
                'size' => $disk.'G',
            ]));
        }

        return $task;
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function getVirtualMachine(string $node, int|string $vmid): array
    {
        return $this->attributes($this->get(
            'nodes/'.$this->normalizeNodeName($node).'/qemu/'.$this->normalizeVirtualMachineId($vmid).'/status/current',
        ));
    }

    /**
     * @return ($node is null ? Nodes : Node)
     */
    public function nodes(?string $node = null): Nodes|Node
    {
        return $node === null ? new Nodes($this) : $this->node($node);
    }

    public function node(string $node): Node
    {
        return new Node($this, $node);
    }

    /**
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    private function taskId(Response $response): string
    {
        $task = $response->throw()->json('data');

        if (! is_string($task) || trim($task) === '') {
            throw new UnexpectedValueException('Expected a task ID in the Proxmox API response "data" field.');
        }

        return $task;
    }

    /**
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    private function waitForTask(string $node, string $task, int $timeout): void
    {
        $deadline = Carbon::now()->addSeconds($timeout);

        while (Carbon::now()->lessThan($deadline)) {
            $data = $this->attributes($this->get('nodes/'.$node.'/tasks/'.rawurlencode($task).'/status'));
            $status = $data['status'] ?? null;

            if ($status === 'stopped') {
                $exitStatus = $data['exitstatus'] ?? null;

                if (! is_string($exitStatus) || $exitStatus === '') {
                    throw new UnexpectedValueException('Expected an exitstatus in the Proxmox task response.');
                }

                if ($exitStatus !== 'OK') {
                    throw new RuntimeException('Proxmox task '.$task.' failed: '.$exitStatus);
                }

                return;
            }

            if ($status !== 'running') {
                throw new UnexpectedValueException('Expected a running or stopped Proxmox task status.');
            }

            Sleep::for(1)->second();
        }

        throw new RuntimeException('Timed out waiting for Proxmox task '.$task.'. The import may still be running; no resize was submitted.');
    }

    /**
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    private function collection(Response $response): Collection
    {
        $data = $response->throw()->json('data');

        if (! is_array($data) || ! array_is_list($data)) {
            throw new UnexpectedValueException('Expected a list in the Proxmox API response "data" field.');
        }

        $items = [];

        foreach ($data as $item) {
            if (! is_array($item)) {
                throw new UnexpectedValueException('Expected array entries in the Proxmox API response "data" field.');
            }

            $items[] = $item;
        }

        return new Collection($items);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    private function attributes(Response $response): array
    {
        $data = $response->throw()->json('data');

        if (! is_array($data) || array_is_list($data)) {
            throw new UnexpectedValueException('Expected an attribute object in the Proxmox API response "data" field.');
        }

        return $data;
    }

    private function pendingRequest(): PendingRequest
    {
        $baseUrl = rtrim(trim($this->baseUrl), '/');

        if ($baseUrl === '') {
            throw new LogicException('Set PROXMOX_URL to your Proxmox API URL.');
        }

        if (trim($this->apiTokenId) === '' || trim($this->apiTokenSecret) === '') {
            throw new LogicException('Set PROXMOX_API_TOKEN_ID and PROXMOX_API_TOKEN_SECRET to authenticate.');
        }

        if ($this->timeout < 1) {
            throw new LogicException('PROXMOX_TIMEOUT must be greater than zero.');
        }

        return Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withHeader(
                'Authorization',
                'PVEAPIToken='.trim($this->apiTokenId).'='.trim($this->apiTokenSecret),
            )
            ->timeout($this->timeout)
            ->withOptions(['verify' => $this->verifySsl]);
    }

    private function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if (
            $endpoint === ''
            || str_starts_with($endpoint, '//')
            || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $endpoint) === 1
        ) {
            throw new InvalidArgumentException('Proxmox API endpoints must be non-empty relative paths.');
        }

        return ltrim($endpoint, '/');
    }

    private function normalizeNodeName(string $node): string
    {
        $node = trim($node);

        if (
            $node === ''
            || $node === '.'
            || $node === '..'
            || str_contains($node, '/')
            || str_contains($node, '\\')
        ) {
            throw new InvalidArgumentException('Proxmox node name must be a non-empty path segment.');
        }

        return rawurlencode($node);
    }

    private function normalizeVirtualMachineId(int|string $vmid): string
    {
        $vmid = (string) $vmid;

        if (preg_match('/^[1-9][0-9]*$/D', $vmid) !== 1) {
            throw new InvalidArgumentException('Proxmox VM ID must be a positive integer.');
        }

        return $vmid;
    }
}
