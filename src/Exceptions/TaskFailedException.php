<?php

declare(strict_types=1);

namespace Proxmox\Exceptions;

use RuntimeException;

class TaskFailedException extends RuntimeException implements ProxmoxException
{
    public function __construct(
        public readonly string $upid,
        public readonly string $exitStatus,
    ) {
        parent::__construct("Proxmox task {$upid} failed: {$exitStatus}");
    }
}
