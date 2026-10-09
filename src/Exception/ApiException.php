<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * The service returned an HTTP error status (4xx or 5xx).
 *
 * Subclasses narrow the category; use {@see hasErrorCode()} to react to documented
 * business error codes such as SEAPI-00010 ("no results").
 */
class ApiException extends \RuntimeException implements EdoreczeniaException
{
    /**
     * @param list<ApiError> $errors
     */
    final public function __construct(
        string $message,
        private readonly int $statusCode,
        private readonly array $errors = [],
        private readonly ?string $eventId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    /**
     * @param list<ApiError> $errors
     */
    public static function fromResponse(int $statusCode, string $operation, array $errors = [], ?string $eventId = null): self
    {
        $class = match (true) {
            $statusCode === 400 => BadRequestException::class,
            $statusCode === 401 => AuthenticationException::class,
            $statusCode === 403 => AuthorizationException::class,
            $statusCode === 404 => NotFoundException::class,
            $statusCode === 429 => RateLimitException::class,
            $statusCode >= 500 => ServerException::class,
            default => self::class,
        };

        $summary = $errors === []
            ? 'no error details in response'
            : implode('; ', array_map(static fn(ApiError $e): string => $e->code . ': ' . $e->message, $errors));

        $message = \sprintf('"%s" failed with HTTP %d (%s)', $operation, $statusCode, $summary);
        if ($eventId !== null) {
            $message .= \sprintf(' [event id: %s]', $eventId);
        }

        return new $class($message, $statusCode, $errors, $eventId);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return list<ApiError>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Server-side event identifier (SE API "id"), useful when reporting incidents.
     */
    public function getEventId(): ?string
    {
        return $this->eventId;
    }

    public function hasErrorCode(string $code): bool
    {
        foreach ($this->errors as $error) {
            if ($error->code === $code) {
                return true;
            }
        }

        return false;
    }
}
