<?php

declare(strict_types=1);

namespace Proxmox\Support;

use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Proxmox\Exceptions\UnexpectedResponseException;

/**
 * Unwraps the "data" envelope of Proxmox API responses.
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
        return $data;
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

        return $data;
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
}
