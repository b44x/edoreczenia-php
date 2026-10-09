<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Support;

use Psr\SimpleCache\CacheInterface;

/**
 * Minimal PSR-16 store; TTLs are recorded but not enforced.
 */
final class ArrayCache implements CacheInterface
{
    /** @var array<string, mixed> */
    public array $items = [];

    /** @var array<string, int|null> */
    public array $ttls = [];

    public function get(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, $this->items) ? $this->items[$key] : $default;
    }

    public function set(string $key, mixed $value, \DateInterval|int|null $ttl = null): bool
    {
        $this->items[$key] = $value;
        $this->ttls[$key] = \is_int($ttl) ? $ttl : null;

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->items[$key], $this->ttls[$key]);

        return true;
    }

    public function clear(): bool
    {
        $this->items = [];
        $this->ttls = [];

        return true;
    }

    /**
     * @param iterable<string> $keys
     *
     * @return array<string, mixed>
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key, $default);
        }

        return $result;
    }

    /**
     * @param iterable<mixed, mixed> $values
     */
    public function setMultiple(iterable $values, \DateInterval|int|null $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            if (\is_string($key)) {
                $this->set($key, $value, $ttl);
            }
        }

        return true;
    }

    /**
     * @param iterable<string> $keys
     */
    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }

        return true;
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->items);
    }
}
