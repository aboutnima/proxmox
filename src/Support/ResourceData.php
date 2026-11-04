<?php

declare(strict_types=1);

namespace Proxmox\Support;

/**
 * Converts Proxmox resource values to the units used by the package setters.
 *
 * @internal
 */
final class ResourceData
{
    private const int MEBIBYTE = 1024 ** 2;

    private const int GIBIBYTE = 1024 ** 3;

    public static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }

        return 0;
    }

    public static function percent(mixed $fraction): float
    {
        return is_int($fraction) || is_float($fraction) || (is_string($fraction) && is_numeric($fraction))
            ? round((float) $fraction * 100, 2)
            : 0.0;
    }

    /**
     * Convert bytes to whole MiB, rounding down.
     */
    public static function mebibytes(mixed $bytes): int
    {
        return intdiv(max(0, self::integer($bytes)), self::MEBIBYTE);
    }

    /**
     * Convert bytes to whole GiB, rounding down.
     */
    public static function gibibytes(mixed $bytes): int
    {
        return intdiv(max(0, self::integer($bytes)), self::GIBIBYTE);
    }

    /**
     * Read the VM memory in MiB from "4096" or Proxmox 9's "current=4096" format.
     */
    public static function memory(mixed $memory): int
    {
        if (is_string($memory) && preg_match('/(?:^|,)(?:current=)?(\d+)(?:,|$)/', $memory, $matches) === 1) {
            return (int) $matches[1];
        }

        return self::integer($memory) ?: 512;
    }

    /**
     * Extract guest disks from a VM configuration, skipping CD-ROMs and Cloud-Init drives.
     *
     * @param  array<array-key, mixed>  $config
     * @return array<string, array{storage: string|null, size: int}>
     */
    public static function disks(array $config): array
    {
        $disks = [];

        foreach ($config as $key => $value) {
            if (! is_string($key) || ! is_string($value) || preg_match('/^(?:ide|sata|scsi|virtio)\d+$/D', $key) !== 1) {
                continue;
            }

            $volume = explode(',', $value, 2)[0];

            if ($volume === 'none' || str_contains($volume, 'cloudinit') || preg_match('/(?:^|,)media=cdrom(?:,|$)/', $value) === 1) {
                continue;
            }

            $disks[$key] = [
                'storage' => preg_match('/^([a-zA-Z][a-zA-Z0-9_.-]*):/', $volume, $storage) === 1 ? $storage[1] : null,
                'size' => self::gibibytes(self::diskSize($value)),
            ];
        }

        ksort($disks, SORT_NATURAL);

        return $disks;
    }

    private static function diskSize(string $value): int
    {
        if (preg_match('/(?:^|,)size=(\d+(?:\.\d+)?)([KMGT]?)(?:,|$)/', $value, $matches) !== 1) {
            return 0;
        }

        $exponent = ['' => 0, 'K' => 1, 'M' => 2, 'G' => 3, 'T' => 4][$matches[2]];

        return (int) round((float) $matches[1] * 1024 ** $exponent);
    }
}
