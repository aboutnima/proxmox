<?php

declare(strict_types=1);

namespace Proxmox;

use Illuminate\Support\Facades\Config;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Models\Cluster;
use Proxmox\Models\Node;
use Proxmox\Models\VirtualMachine;

/**
 * Reads the package mode and the configured inventory model classes.
 */
final class Inventory
{
    public const string API = 'api';

    public const string INVENTORY = 'inventory';

    /**
     * @throws InvalidConfigurationException
     */
    public static function mode(): string
    {
        $mode = Config::get('proxmox.mode') ?? self::API;

        if ($mode === '') {
            return self::API;
        }

        if (! in_array($mode, [self::API, self::INVENTORY], true)) {
            throw InvalidConfigurationException::invalidMode($mode);
        }

        return $mode;
    }

    /**
     * @throws InvalidConfigurationException
     */
    public static function enabled(): bool
    {
        return self::mode() === self::INVENTORY;
    }

    /**
     * @return class-string<Cluster>
     *
     * @throws InvalidConfigurationException
     */
    public static function clusterModel(): string
    {
        return self::model('cluster', Cluster::class);
    }

    /**
     * @return class-string<Node>
     *
     * @throws InvalidConfigurationException
     */
    public static function nodeModel(): string
    {
        return self::model('node', Node::class);
    }

    /**
     * @return class-string<VirtualMachine>
     *
     * @throws InvalidConfigurationException
     */
    public static function virtualMachineModel(): string
    {
        return self::model('virtual_machine', VirtualMachine::class);
    }

    /**
     * @template TModel of object
     *
     * @param  class-string<TModel>  $default
     * @return class-string<TModel>
     *
     * @throws InvalidConfigurationException
     */
    private static function model(string $key, string $default): string
    {
        $model = Config::get("proxmox.models.{$key}", $default);

        if (! is_string($model) || ! is_a($model, $default, true)) {
            throw InvalidConfigurationException::invalidModel($key, $default);
        }

        return $model;
    }
}
