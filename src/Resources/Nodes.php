<?php

declare(strict_types=1);

namespace Proxmox\Resources;

use Illuminate\Support\Collection;
use Proxmox\Proxmox;

readonly class Nodes extends ResourceQuery
{
    public function __construct(Proxmox $client)
    {
        parent::__construct(fn (): Collection => $client->getNodes());
    }
}
