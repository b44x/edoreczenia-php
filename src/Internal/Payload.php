<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Internal;

use B4x\Edoreczenia\Exception\UnexpectedResponseException;

/**
 * Strict, path-aware reader for decoded JSON objects.
 *
 * Unknown fields are ignored (forward compatibility). Missing or mistyped required
 * fields raise {@see UnexpectedResponseException} naming the JSON path, never the value.
 * JSON null is treated as an absent optional field.
 *
 * @internal
 */
final class Payload
{
    /**
     * @param array<mixed> $data
     */
    public function __construct(
        private readonly array $data,
        private readonly string $path = '$',
    ) {}

    /**
     * @param array<mixed> $data
     */
    public static function root(array $data): self
    {
        if ($data !== [] && array_is_list($data)) {
            throw new UnexpectedResponseException('Unexpected response: JSON object expected at "$", got array.');
        }

        return new self($data);
    }

    /**
     * Root document that is a JSON array of objects.
     *
     * @param array<mixed> $data
     *
     * @return list<self>
     */
    public static function rootObjectList(array $data): array
    {
        return (new self(['items' => $data], '$'))->objectList('items');
    }

    /**
     * Root document that is a JSON array of strings.
     *
     * @param array<mixed> $data
     *
     * @return list<string>
     */
    public static function rootStringList(array $data): array
    {
        return (new self(['items' => $data], '$'))->stringList('items');
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function string(string $key): string
    {
        return $this->optionalString($key) ?? throw $this->missing($key);
    }

    public function optionalString(string $key): ?string
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (\is_string($value)) {
            return $value;
        }
        // Some fields are declared as strings but carry numbers in examples (e.g. "httpCode": 400).
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        throw $this->mistyped($key, 'string', $value);
    }

    public function bool(string $key): bool
    {
        return $this->optionalBool($key) ?? throw $this->missing($key);
    }

    public function optionalBool(string $key): ?bool
    {
        $value = $this->data[$key] ?? null;
        if ($value === null || \is_bool($value)) {
            return $value;
        }

        throw $this->mistyped($key, 'boolean', $value);
    }

    public function int(string $key): int
    {
        return $this->optionalInt($key) ?? throw $this->missing($key);
    }

    public function optionalInt(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return match (true) {
            $value === null => null,
            \is_int($value) => $value,
            \is_float($value) && floor($value) === $value => (int) $value,
            \is_string($value) && preg_match('/^-?\d+$/', $value) === 1 => (int) $value,
            default => throw $this->mistyped($key, 'integer', $value),
        };
    }

    public function dateTime(string $key): \DateTimeImmutable
    {
        return $this->optionalDateTime($key) ?? throw $this->missing($key);
    }

    /**
     * RFC 3339 / ISO 8601 date-time ("format: date-time" in the contract).
     */
    public function optionalDateTime(string $key): ?\DateTimeImmutable
    {
        $value = $this->optionalString($key);
        if ($value === null) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new UnexpectedResponseException(\sprintf('Unexpected response: field "%s.%s" is not a valid date-time.', $this->path, $key));
        }
    }

    /**
     * Object with string values ("additionalProperties: type: string"). Scalar values are converted to strings.
     *
     * @return array<string, string>
     */
    public function stringMap(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->mistyped($key, 'object', $value);
        }

        $map = [];
        foreach ($value as $name => $item) {
            if (!\is_scalar($item)) {
                throw $this->mistyped($key . '.' . $name, 'string', $item);
            }
            $map[(string) $name] = \is_bool($item) ? ($item ? 'true' : 'false') : (string) $item;
        }

        return $map;
    }

    public function object(string $key): self
    {
        return $this->optionalObject($key) ?? throw $this->missing($key);
    }

    public function optionalObject(string $key): ?self
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $this->mistyped($key, 'object', $value);
        }

        return new self($value, $this->path . '.' . $key);
    }

    /**
     * @return list<self>
     */
    public function objectList(string $key): array
    {
        $result = [];
        foreach ($this->rawList($key) as $index => $item) {
            if (!\is_array($item) || ($item !== [] && array_is_list($item))) {
                throw $this->mistyped($key . '[' . $index . ']', 'object', $item);
            }
            $result[] = new self($item, \sprintf('%s.%s[%d]', $this->path, $key, $index));
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $result = [];
        foreach ($this->rawList($key) as $index => $item) {
            if (!\is_string($item)) {
                throw $this->mistyped($key . '[' . $index . ']', 'string', $item);
            }
            $result[] = $item;
        }

        return $result;
    }

    /**
     * Returns the matching enum case, the raw string for values unknown to this SDK
     * version (so new server-side values do not break clients), or null when absent.
     *
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|string|null
     */
    public function enumOrString(string $key, string $enum): \BackedEnum|string|null
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) && !\is_int($value)) {
            throw $this->mistyped($key, 'string', $value);
        }

        // Integer-backed enums accept 3 and "3": the contract declares such enums as strings
        // with numeric values, so the wire type is not guaranteed.
        if ((string) (new \ReflectionEnum($enum))->getBackingType() === 'int') {
            $case = \is_int($value) || preg_match('/^\d+$/', $value) === 1 ? $enum::tryFrom((int) $value) : null;
        } else {
            $case = \is_string($value) ? $enum::tryFrom($value) : null;
        }

        return $case ?? (string) $value;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|string
     */
    public function requiredEnumOrString(string $key, string $enum): \BackedEnum|string
    {
        return $this->enumOrString($key, $enum) ?? throw $this->missing($key);
    }

    /**
     * @return list<mixed>
     */
    private function rawList(string $key): array
    {
        $value = $this->data[$key] ?? null;
        if ($value === null) {
            return [];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            throw $this->mistyped($key, 'array', $value);
        }

        return $value;
    }

    private function missing(string $key): UnexpectedResponseException
    {
        return new UnexpectedResponseException(\sprintf('Unexpected response: required field "%s.%s" is missing.', $this->path, $key));
    }

    private function mistyped(string $key, string $expected, mixed $value): UnexpectedResponseException
    {
        return new UnexpectedResponseException(\sprintf(
            'Unexpected response: field "%s.%s" must be %s, got %s.',
            $this->path,
            $key,
            $expected,
            get_debug_type($value),
        ));
    }
}
