<?php

declare(strict_types=1);

namespace Proxmox\Exceptions;

use LogicException;

class InvalidConfigurationException extends LogicException implements ProxmoxException
{
    public static function missingUrl(string $connection): self
    {
        return new self("Set PROXMOX_URL (proxmox.connections.{$connection}.url) to your Proxmox API URL.");
    }

    public static function missingToken(string $connection): self
    {
        return new self("Set PROXMOX_API_TOKEN_ID and PROXMOX_API_TOKEN_SECRET (proxmox.connections.{$connection}) to authenticate.");
    }

    public static function invalidTimeout(string $connection): self
    {
        return new self("PROXMOX_TIMEOUT and PROXMOX_CONNECT_TIMEOUT (proxmox.connections.{$connection}) must be greater than zero.");
    }

    public static function connectionNotConfigured(string $connection): self
    {
        return new self("Proxmox connection [{$connection}] is not configured in proxmox.connections.");
    }
}
