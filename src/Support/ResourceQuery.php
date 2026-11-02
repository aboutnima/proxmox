<?php

declare(strict_types=1);

namespace Proxmox\Support;

use BadMethodCallException;
use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;
use Illuminate\Support\Traits\ForwardsCalls;
use Illuminate\Support\Traits\Macroable;
use InvalidArgumentException;
use Proxmox\Exceptions\InvalidConfigurationException;
use Proxmox\Exceptions\UnexpectedResponseException;

/**
 * A lazy, immutable query over a Proxmox listing endpoint.
 *
 * Constraints never send requests; every terminal method (all, get, first,
 * or a passed-through Collection method) fetches the listing again.
 *
 * @method float|int|null avg(callable|string|null $callback = null)
 * @method bool contains(mixed $key, mixed $operator = null, mixed $value = null)
 * @method int count()
 * @method bool doesntContain(mixed $key, mixed $operator = null, mixed $value = null)
 * @method Collection<int, array<array-key, mixed>> each(callable $callback)
 * @method bool every(callable|string $key, mixed $operator = null, mixed $value = null)
 * @method array<array-key, mixed>|null firstWhere(callable|string $key, mixed $operator = null, mixed $value = null)
 * @method Collection<array-key, Collection<int, array<array-key, mixed>>> groupBy(callable|array<array-key, mixed>|string $groupBy, bool $preserveKeys = false)
 * @method bool isEmpty()
 * @method bool isNotEmpty()
 * @method Collection<array-key, array<array-key, mixed>> keyBy(callable|array<array-key, mixed>|string $keyBy)
 * @method Collection<int, mixed> map(callable $callback)
 * @method mixed max(callable|string|null $callback = null)
 * @method mixed min(callable|string|null $callback = null)
 * @method Collection<array-key, mixed> pluck(string|int|array<array-key, string>|null $value, string|null $key = null)
 * @method array<array-key, mixed> sole(callable|string|null $key = null, mixed $operator = null, mixed $value = null)
 * @method Collection<int, array<array-key, mixed>> sortBy(callable|array<array-key, mixed>|string $callback, int $options = 0, bool $descending = false)
 * @method Collection<int, array<array-key, mixed>> sortByDesc(callable|array<array-key, mixed>|string $callback, int $options = 0)
 * @method float|int sum(callable|string|null $callback = null)
 */
abstract class ResourceQuery
{
    use Conditionable;
    use ForwardsCalls;
    use Macroable {
        __call as macroCall;
    }

    private const array OPERATORS = ['=', '==', '===', '!=', '<>', '!==', '<', '>', '<=', '>='];

    /**
     * Collection methods that run against a freshly fetched listing.
     *
     * @var list<string>
     */
    protected const array PASSTHRU = [
        'avg', 'contains', 'count', 'doesntContain', 'each', 'every', 'firstWhere', 'groupBy', 'isEmpty',
        'isNotEmpty', 'keyBy', 'map', 'max', 'min', 'pluck', 'sole', 'sortBy', 'sortByDesc', 'sum',
    ];

    /**
     * @var list<Closure(Collection<int, array<array-key, mixed>>): Collection<int, array<array-key, mixed>>>
     */
    private array $constraints = [];

    /**
     * Fetch the unfiltered listing from the API.
     *
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    abstract protected function fetch(): Collection;

    /**
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function all(): Collection
    {
        $items = $this->fetch();

        foreach ($this->constraints as $constraint) {
            $items = $constraint($items);
        }

        return $items->values();
    }

    /**
     * @return Collection<int, array<array-key, mixed>>
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function get(): Collection
    {
        return $this->all();
    }

    /**
     * @return array<array-key, mixed>|null
     *
     * @throws ConnectionException
     * @throws InvalidConfigurationException
     * @throws RequestException
     * @throws UnexpectedResponseException
     */
    public function first(): ?array
    {
        return $this->all()->first();
    }

    /**
     * Filter by a key using Laravel collection comparison semantics.
     *
     * @throws InvalidArgumentException
     */
    public function where(string $key, mixed $operator, mixed $value = null): static
    {
        $this->ensureValidKey($key);

        if (func_num_args() === 2) {
            [$value, $operator] = [$operator, '='];
        }

        if (! in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException('Unsupported resource filter operator.');
        }

        return $this->constrain(static fn (Collection $items): Collection => $items->where($key, $operator, $value));
    }

    /**
     * @param  Arrayable<array-key, mixed>|iterable<array-key, mixed>  $values
     *
     * @throws InvalidArgumentException
     */
    public function whereIn(string $key, Arrayable|iterable $values, bool $strict = false): static
    {
        $this->ensureValidKey($key);

        return $this->constrain(static fn (Collection $items): Collection => $items->whereIn($key, $values, $strict));
    }

    /**
     * @param  Arrayable<array-key, mixed>|iterable<array-key, mixed>  $values
     *
     * @throws InvalidArgumentException
     */
    public function whereNotIn(string $key, Arrayable|iterable $values, bool $strict = false): static
    {
        $this->ensureValidKey($key);

        return $this->constrain(static fn (Collection $items): Collection => $items->whereNotIn($key, $values, $strict));
    }

    /**
     * @param  array<int, mixed>  $parameters
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (in_array($method, static::PASSTHRU, true)) {
            return $this->forwardCallTo($this->all(), $method, $parameters);
        }

        static::throwBadMethodCallException($method);
    }

    /**
     * @param  Closure(Collection<int, array<array-key, mixed>>): Collection<int, array<array-key, mixed>>  $constraint
     */
    private function constrain(Closure $constraint): static
    {
        $query = clone $this;
        $query->constraints[] = $constraint;

        return $query;
    }

    /**
     * @throws InvalidArgumentException
     */
    private function ensureValidKey(string $key): void
    {
        if (trim($key) === '') {
            throw new InvalidArgumentException('A resource filter key must not be empty.');
        }
    }
}
