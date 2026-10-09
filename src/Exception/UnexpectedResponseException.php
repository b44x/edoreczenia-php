<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * The server answered, but the response could not be interpreted: malformed JSON,
 * an unexpected content type, or a body that does not match the documented contract.
 *
 * The response body is intentionally never included, because it may contain personal data.
 */
final class UnexpectedResponseException extends \RuntimeException implements EdoreczeniaException
{
    public function __construct(string $message, private readonly ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
