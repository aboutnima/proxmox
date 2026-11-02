<?php

declare(strict_types=1);

namespace Proxmox\Exceptions;

use Throwable;

/**
 * Marker for every exception thrown by the package itself.
 */
interface ProxmoxException extends Throwable {}
