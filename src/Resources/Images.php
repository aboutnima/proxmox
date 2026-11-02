<?php

declare(strict_types=1);

namespace Proxmox\Resources;

use Illuminate\Support\Collection;
use Proxmox\Proxmox;

readonly class Images extends ResourceQuery
{
    public function __construct(Proxmox $client, string $node, string $storage)
    {
        parent::__construct(fn (): Collection => $client->getImages($node, $storage));
    }
}
