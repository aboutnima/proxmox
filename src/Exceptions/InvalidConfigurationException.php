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

    public static function clusterNotFound(string $cluster): self
    {
        return new self("Proxmox cluster [{$cluster}] does not exist. Add it with: php artisan proxmox:create-cluster {$cluster}");
    }

    public static function invalidMode(mixed $mode): self
    {
        $mode = is_scalar($mode) ? (string) $mode : get_debug_type($mode);

        return new self("PROXMOX_MODE (proxmox.mode) must be [api] or [inventory], [{$mode}] given.");
    }

    public static function inventoryModeRequired(): self
    {
        return new self('This feature requires inventory mode. Run: php artisan proxmox:install --mode=inventory');
    }

    /**
     * @param  class-string  $expected
     */
    public static function invalidModel(string $key, string $expected): self
    {
        return new self("proxmox.models.{$key} must be a class that extends [{$expected}].");
    }

    public static function caBundleBlobUnsupported(string $connection): self
    {
        return new self("The CA certificate for connection [{$connection}] needs cURL 7.77 or newer. Use a CA file path instead.");
    }
}
