<?php

declare(strict_types=1);

namespace Proxmox\Resources\VirtualMachines;

use InvalidArgumentException;
use Proxmox\Proxmox;
use Proxmox\Support\ResourceData;
use Proxmox\Support\ResponseData;

/**
 * Fetches and formats the VM's RRD history.
 *
 * @internal
 */
final readonly class VirtualMachineHistory
{
    public function __construct(private Proxmox $client, private string $path) {}

    /**
     * @return list<array{time: int, vcpus: int|null, percent: float|null}>
     */
    public function cpu(string $timeframe, string $consolidation): array
    {
        return self::cpuSeries($this->points($timeframe, $consolidation));
    }

    /**
     * @return list<array{time: int, total: int|null, used: int|null}>
     */
    public function memory(string $timeframe, string $consolidation): array
    {
        return self::memorySeries($this->points($timeframe, $consolidation));
    }

    /**
     * @return list<array{time: int, in: float|null, out: float|null}>
     */
    public function network(string $timeframe, string $consolidation): array
    {
        return self::networkSeries($this->points($timeframe, $consolidation));
    }

    /**
     * @return list<array{time: int, read: float|null, write: float|null}>
     */
    public function disk(string $timeframe, string $consolidation): array
    {
        return self::diskSeries($this->points($timeframe, $consolidation));
    }

    /**
     * @return array{
     *     cpu: list<array{time: int, vcpus: int|null, percent: float|null}>,
     *     memory: list<array{time: int, total: int|null, used: int|null}>,
     *     network: list<array{time: int, in: float|null, out: float|null}>,
     *     disk: list<array{time: int, read: float|null, write: float|null}>,
     * }
     */
    public function all(string $timeframe, string $consolidation): array
    {
        $points = $this->points($timeframe, $consolidation);

        return [
            'cpu' => self::cpuSeries($points),
            'memory' => self::memorySeries($points),
            'network' => self::networkSeries($points),
            'disk' => self::diskSeries($points),
        ];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function points(string $timeframe, string $consolidation): array
    {
        if (! in_array($timeframe, ['hour', 'day', 'week', 'month', 'year'], true)) {
            throw new InvalidArgumentException('timeframe must be hour, day, week, month, or year.');
        }

        if (! in_array($consolidation, ['average', 'max'], true)) {
            throw new InvalidArgumentException('consolidation must be average or max.');
        }

        $points = [];

        foreach (ResponseData::list($this->client->get($this->path, [
            'timeframe' => $timeframe,
            'cf' => strtoupper($consolidation),
        ])) as $point) {
            if (is_int($point['time'] ?? null)) {
                $points[] = $point;
            }
        }

        return $points;
    }

    /**
     * @param  list<array<array-key, mixed>>  $points
     * @return list<array{time: int, vcpus: int|null, percent: float|null}>
     */
    private static function cpuSeries(array $points): array
    {
        return array_map(static function (array $point): array {
            $vcpus = ResourceData::nullableNumber($point['maxcpu'] ?? null, 0);
            $cpu = ResourceData::nullableNumber($point['cpu'] ?? null, 6);

            return [
                'time' => ResourceData::integer($point['time'] ?? 0),
                'vcpus' => $vcpus === null ? null : (int) $vcpus,
                'percent' => $cpu === null ? null : ResourceData::percent($cpu),
            ];
        }, $points);
    }

    /**
     * @param  list<array<array-key, mixed>>  $points
     * @return list<array{time: int, total: int|null, used: int|null}>
     */
    private static function memorySeries(array $points): array
    {
        return array_map(static fn (array $point): array => [
            'time' => ResourceData::integer($point['time'] ?? 0),
            'total' => ResourceData::nullableNumber($point['maxmem'] ?? null) === null ? null : ResourceData::mebibytes($point['maxmem']),
            'used' => ResourceData::nullableNumber($point['mem'] ?? null) === null ? null : ResourceData::mebibytes($point['mem']),
        ], $points);
    }

    /**
     * @param  list<array<array-key, mixed>>  $points
     * @return list<array{time: int, in: float|null, out: float|null}>
     */
    private static function networkSeries(array $points): array
    {
        return array_map(static fn (array $point): array => [
            'time' => ResourceData::integer($point['time'] ?? 0),
            'in' => ResourceData::nullableNumber($point['netin'] ?? null),
            'out' => ResourceData::nullableNumber($point['netout'] ?? null),
        ], $points);
    }

    /**
     * @param  list<array<array-key, mixed>>  $points
     * @return list<array{time: int, read: float|null, write: float|null}>
     */
    private static function diskSeries(array $points): array
    {
        return array_map(static fn (array $point): array => [
            'time' => ResourceData::integer($point['time'] ?? 0),
            'read' => ResourceData::nullableNumber($point['diskread'] ?? null),
            'write' => ResourceData::nullableNumber($point['diskwrite'] ?? null),
        ], $points);
    }
}
