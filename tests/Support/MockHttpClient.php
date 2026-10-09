<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Support;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Deterministic PSR-18 client: replays queued responses or exceptions and records requests.
 *
 * It never touches the network. A request with an empty queue fails the test, so an
 * unexpected extra call (e.g. an unwanted retry) is always detected.
 */
final class MockHttpClient implements ClientInterface
{
    /** @var list<ResponseInterface|ClientExceptionInterface> */
    private array $queue = [];

    /** @var list<RequestInterface> */
    public array $requests = [];

    public function queue(ResponseInterface|ClientExceptionInterface ...$items): self
    {
        foreach ($items as $item) {
            $this->queue[] = $item;
        }

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;
        $next = array_shift($this->queue);

        if ($next === null) {
            throw new \LogicException(\sprintf('Unexpected HTTP request %s %s: no queued response.', $request->getMethod(), (string) $request->getUri()));
        }
        if ($next instanceof ClientExceptionInterface) {
            throw $next;
        }

        return $next;
    }

    public function lastRequest(): RequestInterface
    {
        return $this->requests[array_key_last($this->requests) ?? throw new \LogicException('No request was sent.')];
    }

    public function pending(): int
    {
        return \count($this->queue);
    }
}
