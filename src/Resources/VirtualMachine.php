<?php

declare(strict_types=1);

namespace Proxmox\Resources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use LogicException;
use Proxmox\Proxmox;
use UnexpectedValueException;

readonly class VirtualMachine
{
    public function __construct(
        private Proxmox $client,
        private string $node,
        private int|string $vmid,
    ) {}

    /**
     * @return array<array-key, mixed>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function get(): array
    {
        return $this->client->getVirtualMachine($this->node, $this->vmid);
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
    public function first(): array
    {
        return $this->get();
    }
}
