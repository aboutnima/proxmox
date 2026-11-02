<?php

declare(strict_types=1);

namespace Proxmox\Resources\Nodes;

use Illuminate\Support\Collection;
use Proxmox\Proxmox;
use Proxmox\Support\ResourceQuery;
use Proxmox\Support\ResponseData;

class Nodes extends ResourceQuery
{
    public function __construct(private readonly Proxmox $client) {}

    protected function fetch(): Collection
    {
        return ResponseData::list($this->client->get('nodes'));
    }
}
