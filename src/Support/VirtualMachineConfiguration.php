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
        foreach ([
            'disk' => 'scsi0',
            'image' => 'scsi0',
            'bridge' => 'net0',
            'ip' => 'ipconfig0',
            'user' => 'ciuser',
            'password' => 'cipassword',
            'ssh_key' => 'sshkeys',
        ] as $field => $native) {
            if (array_key_exists($field, $configuration) && array_key_exists($native, $configuration)) {
                throw new InvalidArgumentException("Use either {$field} or {$native}, not both.");
            }
        }

        $cloudInit = array_intersect(['image', 'ip', 'user', 'password', 'ssh_key'], array_keys($configuration)) !== [];

        if ($cloudInit && array_key_exists('ide2', $configuration)) {
            throw new InvalidArgumentException('Readable Cloud-Init fields automatically configure ide2; use native fields with a custom ide2.');
        }

        if (array_key_exists('disk', $configuration)) {
            $disk = $configuration['disk'];

            if (! is_int($disk) || $disk < 1) {
                throw new InvalidArgumentException('disk must be a positive integer in GiB.');
            }

            $configuration['scsi0'] = self::identifier($configuration, 'storage').':'.$disk;
        }

        if (array_key_exists('image', $configuration)) {
            $configuration['scsi0'] = self::identifier($configuration, 'storage').':0,import-from='.self::image($configuration['image']);
            $configuration += ['boot' => 'order=scsi0', 'serial0' => 'socket', 'vga' => 'serial0'];
        }

        if (array_key_exists('disk', $configuration) || array_key_exists('image', $configuration)) {
            $configuration += ['scsihw' => 'virtio-scsi-pci'];
        }

        if (array_key_exists('bridge', $configuration)) {
            $configuration['net0'] = 'virtio,bridge='.self::identifier($configuration, 'bridge');
        }

        if (array_key_exists('ip', $configuration)) {
            $network = $configuration['net0'] ?? null;

            if (! is_string($network) || trim($network) === '') {
                throw new InvalidArgumentException('ip requires a bridge or an explicit net0 network card.');
            }

            $configuration['ipconfig0'] = self::ipConfiguration($configuration);
        } elseif (array_key_exists('prefix', $configuration) || array_key_exists('gateway', $configuration)) {
            throw new InvalidArgumentException('prefix and gateway require an ip.');
        }

        foreach (['user' => 'ciuser', 'password' => 'cipassword', 'ssh_key' => 'sshkeys'] as $field => $native) {
            if (! array_key_exists($field, $configuration)) {
                continue;
            }

            $value = $configuration[$field];

            if (! is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException("{$field} must be a non-empty string.");
            }

            $configuration[$native] = $field === 'ssh_key' ? rawurlencode($value) : $value;
            unset($configuration[$field]);
        }

        if ($cloudInit) {
            $configuration['ide2'] = self::identifier($configuration, 'storage').':cloudinit';
        }

        unset(
            $configuration['disk'],
            $configuration['image'],
            $configuration['bridge'],
            $configuration['ip'],
            $configuration['prefix'],
            $configuration['gateway'],
        );

        return $configuration;
    }

    private static function image(mixed $image): string
    {
        if (! is_string($image) || preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]*:import\/[a-zA-Z0-9][a-zA-Z0-9_.-]*\.qcow2$/D', $image) !== 1) {
            throw new InvalidArgumentException('image must be an importable QCOW2 volume ID such as local:import/ubuntu.qcow2.');
        }

        return $image;
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
