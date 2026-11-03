<?php

declare(strict_types=1);

namespace Proxmox\Resources\Tasks;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\TaskFailedException;
use Proxmox\Exceptions\TaskTimeoutException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Proxmox;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResponseData;
use Stringable;

/**
 * A Proxmox background task identified by its UPID.
 */
readonly class Task implements Stringable
{
    public string $node;

    public string $upid;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private Proxmox $client, string $node, string $upid)
    {
        if (trim($upid) === '') {
            throw new InvalidArgumentException('Proxmox task UPID must not be empty.');
        }

        $this->node = Identifier::node($node);
        $this->upid = $upid;
    }

    /**
     * Create a task from a full UPID such as "UPID:pve1:0001F2A3:...".
     *
     * @throws InvalidArgumentException
     */
    public static function fromUpid(Proxmox $client, string $upid): self
    {
        if (preg_match('/^UPID:([^:]+):/', $upid, $matches) !== 1) {
            throw new InvalidArgumentException('Proxmox task UPID must look like "UPID:node:...".');
        }

        return new self($client, $matches[1], $upid);
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
        return ResponseData::attributes($this->client->get($this->path('status')));
    }

    /**
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function isRunning(): bool
    {
        return $this->state($this->status()) === 'running';
    }

    /**
     * Block until the task stops, polling every $interval seconds.
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws TaskFailedException
     * @throws TaskTimeoutException
     * @throws UnexpectedResponseException
     */
    public function wait(int $timeout = 300, int $interval = 1): static
    {
        if ($timeout < 1 || $interval < 1) {
            throw new InvalidArgumentException('The task wait timeout and interval must be positive numbers of seconds.');
        }

        $deadline = Carbon::now()->addSeconds($timeout);

        while (Carbon::now()->lessThan($deadline)) {
            $status = $this->status();

            if ($this->state($status) === 'stopped') {
                $exitStatus = $status['exitstatus'] ?? null;

                if (! is_string($exitStatus) || $exitStatus === '') {
                    throw UnexpectedResponseException::expectedTaskExitStatus();
                }

                // Proxmox treats "WARNINGS: n" as a successful task (PVE::Tools::upid_status_is_error).
                if ($exitStatus !== 'OK' && preg_match('/^WARNINGS: \d+$/D', $exitStatus) !== 1) {
                    throw new TaskFailedException($this->upid, $exitStatus);
                }

                return $this;
            }

            Sleep::for($interval)->seconds();
        }

        throw new TaskTimeoutException($this->upid, $timeout);
    }

    /**
     * @return list<array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function log(int $start = 0, int $limit = 50): array
    {
        return ResponseData::list($this->client->get($this->path('log'), ['start' => $start, 'limit' => $limit]));
    }

    public function __toString(): string
    {
        return $this->upid;
    }

    /**
     * @param  array<array-key, mixed>  $status
     * @return 'running'|'stopped'
     *
     * @throws UnexpectedResponseException
     */
    private function state(array $status): string
    {
        $state = $status['status'] ?? null;

        if ($state !== 'running' && $state !== 'stopped') {
            throw UnexpectedResponseException::expectedTaskStatus();
        }

        return $state;
    }

    private function path(string $path): string
    {
        return 'nodes/'.rawurlencode($this->node).'/tasks/'.rawurlencode($this->upid).'/'.$path;
    }
}
