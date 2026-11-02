<?php

declare(strict_types=1);

namespace Proxmox\Support;

use InvalidArgumentException;

/**
 * @internal
 */
final class VirtualMachineConfiguration
{
    /**
     * @param  array<string, mixed>  $configuration
     * @return array<string, mixed>
     */
    public static function toApiParameters(array $configuration): array
    {
        foreach (['disk' => 'scsi0', 'bridge' => 'net0', 'ip' => 'ipconfig0'] as $field => $native) {
            if (array_key_exists($field, $configuration) && array_key_exists($native, $configuration)) {
                throw new InvalidArgumentException("Use either {$field} or {$native}, not both.");
            }
        }

        if (array_key_exists('disk', $configuration)) {
            $disk = $configuration['disk'];

            if (! is_int($disk) || $disk < 1) {
                throw new InvalidArgumentException('disk must be a positive integer in GiB.');
            }

            $configuration['scsi0'] = self::identifier($configuration, 'storage').':'.$disk;

            if (! array_key_exists('scsihw', $configuration)) {
                $configuration['scsihw'] = 'virtio-scsi-pci';
            }
        }

        if (array_key_exists('bridge', $configuration)) {
            $configuration['net0'] = 'virtio,bridge='.self::identifier($configuration, 'bridge');
        }

        if (array_key_exists('ip', $configuration)) {
            if (array_key_exists('ide2', $configuration)) {
                throw new InvalidArgumentException('ip automatically configures ide2; use native ipconfig0 with a custom ide2.');
            }

            $storage = self::identifier($configuration, 'storage');
            $network = $configuration['net0'] ?? null;

            if (! is_string($network) || trim($network) === '') {
                throw new InvalidArgumentException('ip requires a bridge or an explicit net0 network card.');
            }

            $configuration['ipconfig0'] = self::ipConfiguration($configuration);
            $configuration['ide2'] = $storage.':cloudinit';
        } elseif (array_key_exists('prefix', $configuration) || array_key_exists('gateway', $configuration)) {
            throw new InvalidArgumentException('prefix and gateway require an ip.');
        }

        unset(
            $configuration['disk'],
            $configuration['bridge'],
            $configuration['ip'],
            $configuration['prefix'],
            $configuration['gateway'],
        );

        return $configuration;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private static function identifier(array $configuration, string $field): string
    {
        $value = $configuration[$field] ?? null;

        if (! is_string($value) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*$/D', $value) !== 1) {
            throw new InvalidArgumentException("{$field} must be a non-empty identifier containing only letters, numbers, underscores, dots, and hyphens, starting with a letter.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $configuration
     */
    private static function ipConfiguration(array $configuration): string
    {
        $ip = $configuration['ip'];

        if ($ip === 'dhcp') {
            foreach (['prefix', 'gateway'] as $field) {
                if (array_key_exists($field, $configuration)) {
                    throw new InvalidArgumentException("{$field} cannot be used with DHCP.");
                }
            }

            return 'ip=dhcp';
        }

        if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new InvalidArgumentException('ip must be an IPv4 address without a prefix, or "dhcp".');
        }

        $prefix = $configuration['prefix'] ?? null;

        if (! is_int($prefix) || $prefix < 0 || $prefix > 32) {
            throw new InvalidArgumentException('A static ip requires an integer prefix between 0 and 32.');
        }

        $value = 'ip='.$ip.'/'.$prefix;

        if (array_key_exists('gateway', $configuration)) {
            $gateway = $configuration['gateway'];

            if (! is_string($gateway) || filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new InvalidArgumentException('gateway must be an IPv4 address.');
            }

            $value .= ',gw='.$gateway;
        }

        return $value;
    }
}
