<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * Non-seekable stream of unknown size that returns at most $chunkSize bytes per read, like a socket or pipe.
 *
 * It refuses getContents() and __toString(), so any code that buffers the whole stream fails the test,
 * and it records the largest single read.
 */
final class ChunkedStream implements StreamInterface
{
    private int $position = 0;

    public int $largestRead = 0;

    public bool $closed = false;

    public function __construct(
        private readonly string $content,
        private readonly int $chunkSize,
    ) {}

    public function __toString(): string
    {
        throw new \LogicException('The whole stream must not be buffered.');
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function detach()
    {
        return null;
    }

    public function getSize(): ?int
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->position >= \strlen($this->content);
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        throw new \RuntimeException('Not seekable.');
    }

    public function rewind(): void
    {
        throw new \RuntimeException('Not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('Not writable.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        $chunk = substr($this->content, $this->position, min($length, $this->chunkSize));
        $this->position += \strlen($chunk);
        $this->largestRead = max($this->largestRead, \strlen($chunk));

        return $chunk;
    }

    public function getContents(): string
    {
        throw new \LogicException('The whole stream must not be buffered.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(?string $key = null): ?array
    {
        return $key === null ? [] : null;
    }
}
