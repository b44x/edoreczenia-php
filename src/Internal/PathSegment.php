<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Internal;

/**
 * Encodes a value for use as one URL path segment (RFC 3986 "pchar").
 *
 * ":" and "@" are valid in a path segment and are kept as-is, so a mailbox address such as
 * "AE:PL-12345-67890-ABCDE-12" appears in the URL exactly as in the contract examples
 * ("/{eDeliveryAddress}/messages"). Whether the server also accepts "%3A" is unverified.
 *
 * @internal
 */
final class PathSegment
{
    public static function encode(string $value): string
    {
        return str_replace(['%3A', '%40'], [':', '@'], rawurlencode($value));
    }
}
