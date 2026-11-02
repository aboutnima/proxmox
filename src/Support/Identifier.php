<?php

declare(strict_types=1);

namespace Proxmox\Support;

use InvalidArgumentException;

/**
 * @internal
 */
final class Identifier
{
    public const int MIN_VMID = 100;

    public const int MAX_VMID = 999_999_999;

    /**
     * Validate a node name and return it trimmed (not URL encoded).
     */
    public static function node(string $node): string
    {
        $node = trim($node);

        if (in_array($node, ['', '.', '..'], true) || str_contains($node, '/') || str_contains($node, '\\')) {
            throw new InvalidArgumentException('Proxmox node name must be a non-empty path segment.');
        }

        return $node;
    }

    public static function vmid(mixed $vmid): int
    {
        if (
            (! is_int($vmid) && ! is_string($vmid))
            || preg_match('/^[1-9][0-9]*$/D', (string) $vmid) !== 1
            || (int) $vmid < self::MIN_VMID
            || (int) $vmid > self::MAX_VMID
        ) {
            throw new InvalidArgumentException(sprintf(
                'Proxmox VM ID must be an integer between %d and %d.',
                self::MIN_VMID,
                self::MAX_VMID,
            ));
        }

        return (int) $vmid;
    }

    /**
     * Validate a Proxmox configuration identifier such as a storage or bridge name.
     */
    public static function name(mixed $value, string $field): string
    {
        if (! is_string($value) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-empty identifier containing only letters, numbers, underscores, dots, and hyphens, starting with a letter.");
        }

        return $value;
    }
}
