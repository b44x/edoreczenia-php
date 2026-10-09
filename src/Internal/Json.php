<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Internal;

use B4x\Edoreczenia\Exception\UnexpectedResponseException;
use Psr\Http\Message\ResponseInterface;

/**
 * @internal
 */
final class Json
{
    /** Upper bound for bodies read into memory by this helper (JSON responses are small). */
    public const MAX_BODY_BYTES = 10 * 1024 * 1024;

    /**
     * @param array<mixed> $data
     */
    public static function encode(array $data): string
    {
        return json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * Decodes a JSON object or array from a successful response.
     *
     * @return array<mixed>
     */
    public static function decodeResponse(ResponseInterface $response, string $operation): array
    {
        $status = $response->getStatusCode();
        $contentType = strtolower($response->getHeaderLine('Content-Type'));

        if ($contentType !== '' && !str_contains($contentType, 'json')) {
            throw new UnexpectedResponseException(
                \sprintf('"%s" returned HTTP %d with unexpected content type "%s" (JSON expected).', $operation, $status, strtok($contentType, ';')),
                $status,
            );
        }

        $body = self::readBody($response, self::MAX_BODY_BYTES);
        if ($body === null) {
            throw new UnexpectedResponseException(\sprintf('"%s" returned a response body larger than %d bytes.', $operation, self::MAX_BODY_BYTES), $status);
        }

        try {
            $decoded = json_decode($body, true, 128, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            throw new UnexpectedResponseException(\sprintf('"%s" returned malformed JSON (HTTP %d).', $operation, $status), $status, $e);
        }

        if (!\is_array($decoded)) {
            throw new UnexpectedResponseException(\sprintf('"%s" returned JSON that is not an object or array (HTTP %d).', $operation, $status), $status);
        }

        return $decoded;
    }

    /**
     * Reads at most $limit bytes; returns null when the body is larger.
     */
    public static function readBody(ResponseInterface $response, int $limit): ?string
    {
        $stream = $response->getBody();
        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $buffer = '';
        while (!$stream->eof()) {
            $buffer .= $stream->read(8192);
            if (\strlen($buffer) > $limit) {
                return null;
            }
        }

        return $buffer;
    }
}
