<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Request;

use B4x\Edoreczenia\Exception\InvalidArgumentException;

/**
 * Local checks for values the SDK puts into outgoing messages, drafts and attachments.
 *
 * Every check runs before a request is built, so invalid input never reaches the network.
 *
 * @internal
 */
final class Field
{
    /** RFC 6838 "type/subtype" without parameters. */
    private const MEDIA_TYPE = '~^[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]{0,126}/[A-Za-z0-9][A-Za-z0-9!#$&^_.+-]{0,126}$~';

    /**
     * Non-blank, valid UTF-8 text of at most $maxLength characters (Unicode code points, as JSON Schema counts them).
     */
    public static function text(string $field, string $value, ?int $maxLength = null): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(\sprintf('%s must be valid UTF-8.', $field));
        }
        if (trim($value) === '') {
            throw new InvalidArgumentException(\sprintf('%s must not be empty.', $field));
        }
        if ($maxLength !== null && mb_strlen($value, 'UTF-8') > $maxLength) {
            throw new InvalidArgumentException(\sprintf('%s must be at most %d characters long.', $field, $maxLength));
        }

        return $value;
    }

    /**
     * Identifier or address used verbatim (message, thread, attachment id; e-Delivery address).
     */
    public static function id(string $field, string $value): string
    {
        if ($value === '' || preg_match('/\s/u', $value) === 1 || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException(\sprintf('%s must be a non-empty string without whitespace.', $field));
        }

        return $value;
    }

    /**
     * A plain file name: no directories and no control characters.
     */
    public static function fileName(string $value): string
    {
        self::text('fileName', $value);
        if (preg_match('~[/\\\\\x00-\x1F\x7F]~', $value) === 1 || \in_array(trim($value), ['.', '..'], true)) {
            throw new InvalidArgumentException('fileName must be a plain file name without directories or control characters.');
        }

        return $value;
    }

    public static function contentType(string $value): string
    {
        if (preg_match(self::MEDIA_TYPE, $value) !== 1) {
            throw new InvalidArgumentException('contentType must be a media type such as "application/pdf" (without parameters).');
        }

        return $value;
    }
}
