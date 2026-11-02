<?php

declare(strict_types=1);

namespace Proxmox\Resources;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use Proxmox\Proxmox;
use UnexpectedValueException;

readonly class VirtualMachines extends ResourceQuery
{
    public function __construct(
        private Proxmox $client,
        private string $node,
    ) {
        parent::__construct(fn (): Collection => $client->getVirtualMachines($node));
    }

    /**
     * Create a virtual machine using readable fields or native API parameters.
     *
     * @param  array<string, mixed>  $configuration
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function create(array $configuration): string
    {
        return $this->client->createVirtualMachine($this->node, $configuration);
    }
}
