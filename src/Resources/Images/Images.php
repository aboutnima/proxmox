<?php

declare(strict_types=1);

namespace Proxmox\Resources\Images;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Proxmox\Exceptions\UnexpectedResponseException;
use Proxmox\Proxmox;
use Proxmox\Support\Identifier;
use Proxmox\Support\ResourceQuery;
use Proxmox\Support\ResponseData;

/**
 * QCOW2 source images on storage with the "import" content type enabled.
 */
class Images extends ResourceQuery
{
    public readonly string $node;

    public readonly string $storage;

    /**
     * @throws InvalidArgumentException
     */
    public function __construct(private readonly Proxmox $client, string $node, string $storage)
    {
        $this->node = Identifier::node($node);
        $this->storage = Identifier::name($storage, 'storage');
    }

    protected function fetch(): Collection
    {
        return ResponseData::list($this->client->get(
            'nodes/'.rawurlencode($this->node).'/storage/'.$this->storage.'/content',
            ['content' => 'import'],
        ))->filter(function (array $image): bool {
            if (! is_string($image['format'] ?? null) || ! is_string($image['volid'] ?? null) || $image['volid'] === '') {
                throw UnexpectedResponseException::expectedImage();
            }

            return $image['format'] === 'qcow2';
        })->values();
    }
}
