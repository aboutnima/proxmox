<?php

declare(strict_types=1);

namespace Proxmox\Exceptions;

use LogicException;

class InvalidConfigurationException extends LogicException implements ProxmoxException
{
    public static function missingUrl(string $connection): self
    {
        return new self("Set the URL for Proxmox cluster [{$connection}] to your Proxmox API URL.");
    }

    public static function missingToken(string $connection): self
    {
        return new self("Set api_token_id and api_token_secret for Proxmox cluster [{$connection}] to authenticate.");
    }

    public static function invalidTimeout(string $connection): self
    {
        return new self("The timeout and connect_timeout for Proxmox cluster [{$connection}] must be greater than zero.");
    }

    public static function clusterNotFound(string $cluster): self
    {
        return new self("Proxmox cluster [{$cluster}] does not exist. Add it with: php artisan proxmox:create-cluster {$cluster}");
    }

    public static function invalidClusterModel(): self
    {
        return new self('proxmox.model must be [Proxmox\\Models\\Cluster] or a class extending it.');
    }

    public static function caBundleBlobUnsupported(string $connection): self
    {
        return new self("The CA certificate for connection [{$connection}] needs cURL 7.77 or newer. Use a CA file path instead.");
    }
}
