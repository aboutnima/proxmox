<?php

declare(strict_types=1);

namespace Proxmox\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Proxmox\Exceptions\UnexpectedResponseException;

/**
 * Unwraps API data and sorts object keys while preserving list order.
 *
 * @internal
 */
final class ResponseData
{
    /**
     * @return list<array<array-key, mixed>>
     *
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public static function list(Response $response): array
    {
        $data = $response->throw()->json('data');

        if (! is_array($data) || ! array_is_list($data)) {
            throw UnexpectedResponseException::expectedList();
        }

        foreach ($data as $item) {
            if (! is_array($item)) {
                throw UnexpectedResponseException::expectedListOfArrays();
            }
        }

        /** @var list<array<array-key, mixed>> $data */
        return array_map(self::sortKeys(...), $data);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public static function attributes(Response $response): array
    {
        $data = $response->throw()->json('data');

        if (! is_array($data) || array_is_list($data)) {
            throw UnexpectedResponseException::expectedAttributes();
        }

        return self::sortKeys($data);
    }

    /**
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public static function taskId(Response $response): string
    {
        $task = $response->throw()->json('data');

        if (! is_string($task) || trim($task) === '') {
            throw UnexpectedResponseException::expectedTaskId();
        }

        return $task;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function sortKeys(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeys($value);
            }
        }

        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        return $data;
    }
}
