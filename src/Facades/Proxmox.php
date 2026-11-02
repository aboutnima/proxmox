<?php

declare(strict_types=1);

namespace Proxmox\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @mixin \Proxmox\Proxmox
 *
 * @see \Proxmox\Proxmox
 */
class Proxmox extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Proxmox\Proxmox::class;
    }
}
