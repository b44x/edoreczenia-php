<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Cli;

use B4x\Edoreczenia\Internal\Transport;
use Psr\Clock\ClockInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 decorator remembering what the doctor needs for its report: the redacted endpoint
 * of the last request, the last HTTP status and the server "Date" header with the local
 * time it was received at. Headers and bodies are not kept.
 *
 * @internal
 */
final class ObservingHttpClient implements ClientInterface
{
    public ?string $lastEndpoint = null;
    public ?int $lastStatus = null;
    public ?\DateTimeImmutable $serverDate = null;
    public ?\DateTimeImmutable $localDate = null;

    public function __construct(
        private readonly ClientInterface $inner,
        private readonly ClockInterface $clock,
    ) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastEndpoint = Transport::redactUri((string) $request->getUri());
        $this->lastStatus = null;

        $response = $this->inner->sendRequest($request);
        $this->lastStatus = $response->getStatusCode();

        $date = $response->getHeaderLine('Date');
        if ($date !== '') {
            $parsed = \DateTimeImmutable::createFromFormat(\DATE_RFC7231, $date, new \DateTimeZone('UTC'));
            if ($parsed !== false) {
                $this->serverDate = $parsed;
                $this->localDate = $this->clock->now();
            }
        }

        return $response;
    }
}
