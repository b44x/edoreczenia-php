<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Internal;

use B4x\Edoreczenia\Exception\ApiError;
use B4x\Edoreczenia\Exception\ApiException;
use Psr\Http\Message\ResponseInterface;

/**
 * Turns an HTTP error response into an {@see ApiException}.
 *
 * Recognised error bodies:
 *  - SE API:  {"httpCode": "...", "id": "...", "errorCodes": [{"errorCode", "language", "message"}]}
 *  - OAuth:   {"error": "...", "error_description": "..."} (Keycloak token endpoint)
 *  - UA API:  [{"error": "...", "error_description": "...", "messageId": "..."}]
 *
 * Anything else yields an exception without details; the raw body is never copied
 * into the exception because it may contain personal data.
 *
 * @internal
 */
final class ErrorResponse
{
    private const MAX_ERROR_BODY_BYTES = 64 * 1024;

    public static function toException(ResponseInterface $response, string $operation): ApiException
    {
        [$errors, $eventId] = self::parse($response);

        return ApiException::fromResponse($response->getStatusCode(), $operation, $errors, $eventId);
    }

    /**
     * @return array{list<ApiError>, ?string}
     */
    private static function parse(ResponseInterface $response): array
    {
        if (!str_contains(strtolower($response->getHeaderLine('Content-Type')), 'json')
            && $response->getHeaderLine('Content-Type') !== '') {
            return [[], null];
        }

        $body = Json::readBody($response, self::MAX_ERROR_BODY_BYTES);
        if ($body === null || $body === '') {
            return [[], null];
        }

        try {
            $data = json_decode($body, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], null];
        }

        if (!\is_array($data)) {
            return [[], null];
        }

        if (array_is_list($data)) {
            return [self::fromUaList($data), null];
        }

        if (isset($data['errorCodes']) && \is_array($data['errorCodes'])) {
            $eventId = isset($data['id']) && \is_string($data['id']) ? $data['id'] : null;

            return [self::fromSearchEngine($data['errorCodes']), $eventId];
        }

        if (isset($data['error']) && \is_string($data['error'])) {
            return [[new ApiError($data['error'], self::stringOrEmpty($data['error_description'] ?? null))], null];
        }

        return [[], null];
    }

    /**
     * @param array<mixed> $items
     *
     * @return list<ApiError>
     */
    private static function fromSearchEngine(array $items): array
    {
        $errors = [];
        foreach ($items as $item) {
            if (\is_array($item) && isset($item['errorCode']) && \is_string($item['errorCode'])) {
                $language = isset($item['language']) && \is_string($item['language']) ? $item['language'] : null;
                $errors[] = new ApiError($item['errorCode'], self::stringOrEmpty($item['message'] ?? null), $language);
            }
        }

        return $errors;
    }

    /**
     * @param list<mixed> $items
     *
     * @return list<ApiError>
     */
    private static function fromUaList(array $items): array
    {
        $errors = [];
        foreach ($items as $item) {
            if (\is_array($item) && isset($item['error']) && \is_string($item['error'])) {
                $errors[] = new ApiError($item['error'], self::stringOrEmpty($item['error_description'] ?? null));
            }
        }

        return $errors;
    }

    private static function stringOrEmpty(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
