<?php

declare(strict_types=1);

namespace Proxmox\Exceptions;

use RuntimeException;

class TaskTimeoutException extends RuntimeException implements ProxmoxException
{
    public function __construct(
        public readonly string $upid,
        public readonly int $timeout,
    ) {
        parent::__construct("Timed out after {$timeout} seconds waiting for Proxmox task {$upid}. The task may still be running.");
    }
}
