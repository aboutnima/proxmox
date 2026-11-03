<?php

declare(strict_types=1);

namespace Proxmox;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Fluent;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Resources\Nodes\Node;
use Proxmox\Resources\Tasks\Task;
use Proxmox\Support\ResponseData;
use SensitiveParameter;
use Throwable;

/**
 * @phpstan-consistent-constructor
 */
readonly class Proxmox
{
    private string $baseUrl;

    private string $apiTokenId;

    private string $apiTokenSecret;

    public function __construct(
        private Factory $http,
        string $baseUrl,
        string $apiTokenId,
        #[SensitiveParameter] string $apiTokenSecret,
        private int $timeout = 30,
        private int $connectTimeout = 10,
        private bool $verifySsl = true,
        private int $retries = 0,
        private int $retryDelay = 100,
        private string $name = 'default',
    ) {
        $this->baseUrl = rtrim(trim($baseUrl), '/');
        $this->apiTokenId = trim($apiTokenId);
        $this->apiTokenSecret = trim($apiTokenSecret);
    }

    /**
     * Create a client from a "proxmox.connections.*" configuration array.
     *
     * @param  array<array-key, mixed>  $config
     */
    public static function fromConfig(Factory $http, array $config, string $name = 'default'): static
    {
        $config = new Fluent($config);

        return new static(
            http: $http,
            baseUrl: $config->string('url')->value(),
            apiTokenId: $config->string('api_token_id')->value(),
            apiTokenSecret: $config->string('api_token_secret')->value(),
            timeout: $config->integer('timeout', 30),
            connectTimeout: $config->integer('connect_timeout', 10),
            verifySsl: $config->boolean('verify_ssl', true),
            retries: max(0, $config->integer('retry.times')),
            retryDelay: max(0, $config->integer('retry.sleep', 100)),
            name: $name,
        );
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Send a request to a Proxmox API endpoint.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function request(string $method, string $endpoint, array $options = []): Response
    {
        $method = strtoupper($method);

        return $this->pendingRequest($method)
            ->when(array_key_exists('form_params', $options), fn (PendingRequest $request) => $request->asForm())
            ->send($method, $this->endpoint($endpoint), $options);
    }

    /**
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function get(string $endpoint, array $query = []): Response
    {
        return $this->pendingRequest('GET')->get($this->endpoint($endpoint), $query);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function post(string $endpoint, array $data = []): Response
    {
        return $this->pendingRequest('POST')->asForm()->post($this->endpoint($endpoint), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function put(string $endpoint, array $data = []): Response
    {
        return $this->pendingRequest('PUT')->asForm()->put($this->endpoint($endpoint), $data);
    }

    /**
     * Proxmox only reads DELETE parameters from the query string.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     */
    public function delete(string $endpoint, array $query = []): Response
    {
        return $this->pendingRequest('DELETE')
            ->withQueryParameters($query)
            ->delete($this->endpoint($endpoint));
    }

    /**
     * @return list<array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function nodes(): array
    {
        if (func_num_args() > 0) {
            throw new InvalidArgumentException('Use node($name) to select a node; nodes() only lists nodes.');
        }

        return ResponseData::list($this->get('nodes'));
    }

    /**
     * @throws InvalidArgumentException
     */
    public function node(string $node): Node
    {
        return new Node($this, $node);
    }

    /**
     * Track a task by its UPID; the node is read from the UPID itself.
     *
     * @throws InvalidArgumentException
     */
    public function task(string $upid): Task
    {
        return Task::fromUpid($this, $upid);
    }

    /**
     * Get the next free VM ID in the cluster.
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function nextVirtualMachineId(): int
    {
        $vmid = $this->get('cluster/nextid')->throw()->json('data');

        if ((! is_int($vmid) && ! is_string($vmid)) || preg_match('/^[1-9][0-9]*$/D', (string) $vmid) !== 1) {
            throw UnexpectedResponseException::expectedVirtualMachineId();
        }

        return (int) $vmid;
    }

    /**
     * Keep the API token secret out of dumps.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'name' => $this->name,
            'baseUrl' => $this->baseUrl,
            'apiTokenId' => $this->apiTokenId,
            'apiTokenSecret' => '********',
            'timeout' => $this->timeout,
            'connectTimeout' => $this->connectTimeout,
            'verifySsl' => $this->verifySsl,
            'retries' => $this->retries,
            'retryDelay' => $this->retryDelay,
        ];
    }

    /**
     * @throws InvalidConfigurationException
     */
    private function pendingRequest(string $method): PendingRequest
    {
        if ($this->baseUrl === '') {
            throw InvalidConfigurationException::missingUrl($this->name);
        }

        if ($this->apiTokenId === '' || $this->apiTokenSecret === '') {
            throw InvalidConfigurationException::missingToken($this->name);
        }

        if ($this->timeout < 1 || $this->connectTimeout < 1) {
            throw InvalidConfigurationException::invalidTimeout($this->name);
        }

        return $this->http->createPendingRequest()
            ->baseUrl($this->baseUrl)
            ->acceptJson()
            ->withHeader('Authorization', 'PVEAPIToken='.$this->apiTokenId.'='.$this->apiTokenSecret)
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout)
            ->withOptions(['verify' => $this->verifySsl])
            ->when(
                $method === 'GET' && $this->retries > 0,
                fn (PendingRequest $request) => $request->retry(
                    $this->retries + 1,
                    $this->retryDelay,
                    fn (Throwable $exception): bool => $exception instanceof ConnectionException,
                    throw: false,
                ),
            );
    }

    /**
     * @throws InvalidArgumentException
     */
    private function endpoint(string $endpoint): string
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
}
