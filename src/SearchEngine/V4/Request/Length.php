<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\SearchEngine\V4\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;

/**
 * Length checks mirroring minLength/maxLength of the SE API v4 contract (characters, not bytes).
 *
 * @internal
 */
final class Length
{
    public static function assert(string $field, string $value, int $min, int $max): void
    {
        $length = mb_strlen(trim($value), 'UTF-8');
        if ($length < $min || $length > $max) {
            throw new InvalidArgumentException(\sprintf('%s must be %d-%d characters long.', $field, $min, $max));
        }
    }

    public static function assertOptional(string $field, ?string $value, int $max): void
    {
        if ($value !== null) {
            self::assert($field, $value, 1, $max);
        }
    }
}
