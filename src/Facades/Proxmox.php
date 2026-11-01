<?php

declare(strict_types=1);

namespace Proxmox\Proxmox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Proxmox\Proxmox\Proxmox
 */
class Proxmox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Proxmox\Proxmox\Proxmox::class;
    }
}
