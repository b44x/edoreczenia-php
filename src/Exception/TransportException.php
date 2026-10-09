<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\NetworkExceptionInterface;

/**
 * No usable HTTP response was received: DNS, connection, TLS or timeout failure
 * reported by the PSR-18 client.
 *
 * Note: for a timeout the request may still have reached the server.
 */
final class TransportException extends \RuntimeException implements EdoreczeniaException
{
    public static function fromClientException(ClientExceptionInterface $previous, string $operation): self
    {
        $kind = $previous instanceof NetworkExceptionInterface ? 'Network error' : 'HTTP client error';

        return new self(\sprintf('%s during "%s": %s', $kind, $operation, $previous->getMessage()), 0, $previous);
    }

    public function isNetworkError(): bool
    {
        return $this->getPrevious() instanceof NetworkExceptionInterface;
    }
}
