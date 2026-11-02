<?php

declare(strict_types=1);

namespace Proxmox\Resources;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

readonly class ResourceQuery
{
    /**
     * @param  Closure(): Collection<int, array<array-key, mixed>>  $fetch
     */
    protected function __construct(private Closure $fetch) {}

    /**
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function all(): Collection
    {
        return ($this->fetch)();
    }

    /**
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function get(): Collection
    {
        return $this->all();
    }

    public function where(string $key, mixed $operator, mixed $value = null): self
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A resource filter key must not be empty.');
        }

        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if (! in_array($operator, ['=', '==', '===', '!=', '<>', '!==', '<', '>', '<=', '>='], true)) {
            throw new InvalidArgumentException('Unsupported resource filter operator.');
        }

        return new self(fn (): Collection => $this->all()->where($key, $operator, $value)->values());
    }

    /**
     * @return array<array-key, mixed>|null
     *
     * @throws ConnectionException
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws RequestException
     * @throws UnexpectedValueException
     */
    public function first(): ?array
    {
        return $this->all()->first();
    }
}
