<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Internal;

use Psr\Http\Message\StreamInterface;

/**
 * Read-only stream that concatenates string parts and PSR-7 streams without copying the streams,
 * so a multipart/form-data body with a large file is produced chunk by chunk while the HTTP client reads it.
 *
 * Only {@see getContents()} and {@see __toString()} buffer the whole body, as PSR-7 requires; HTTP clients
 * that send bodies in chunks never call them.
 *
 * @internal
 */
final class MultipartStream implements StreamInterface
{
    /** @var list<string|StreamInterface> */
    private array $parts;

    private int $index = 0;
    private int $offset = 0;
    private int $position = 0;

    /**
     * @param list<string|StreamInterface> $parts
     */
    public function __construct(array $parts)
    {
        $this->parts = $parts;
    }

    public function __toString(): string
    {
        try {
            if ($this->isSeekable()) {
                $this->rewind();
            }

            return $this->getContents();
        } catch (\Throwable) {
            return '';
        }
    }

    public function close(): void
    {
        foreach ($this->parts as $part) {
            if ($part instanceof StreamInterface) {
                $part->close();
            }
        }
        $this->parts = [];
        $this->index = 0;
        $this->offset = 0;
    }

    public function detach()
    {
        $this->close();

        return null;
    }

    public function getSize(): ?int
    {
        $size = 0;
        foreach ($this->parts as $part) {
            $length = \is_string($part) ? \strlen($part) : $part->getSize();
            if ($length === null) {
                return null;
            }
            $size += $length;
        }

        return $size;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        $this->skipExhausted();

        return $this->index >= \count($this->parts);
    }

    public function isSeekable(): bool
    {
        foreach ($this->parts as $part) {
            if ($part instanceof StreamInterface && !$part->isSeekable()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Only rewinding (offset 0 from the start) is supported.
     */
    public function seek(int $offset, int $whence = \SEEK_SET): void
    {
        if ($offset !== 0 || $whence !== \SEEK_SET || !$this->isSeekable()) {
            throw new \RuntimeException('The multipart body can only be rewound to its start.');
        }

        foreach ($this->parts as $part) {
            if ($part instanceof StreamInterface) {
                $part->rewind();
            }
        }
        $this->index = 0;
        $this->offset = 0;
        $this->position = 0;
    }

    public function rewind(): void
    {
        $this->seek(0);
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): int
    {
        throw new \RuntimeException('The multipart body is read-only.');
    }

    public function isReadable(): bool
    {
        return true;
    }

    public function read(int $length): string
    {
        if ($length < 1) {
            return '';
        }

        while ($this->index < \count($this->parts)) {
            $part = $this->parts[$this->index];
            if (\is_string($part)) {
                $chunk = substr($part, $this->offset, $length);
                $this->offset += \strlen($chunk);
            } else {
                $chunk = $part->eof() ? '' : $part->read($length);
                if ($chunk === '' && !$part->eof()) {
                    // Nothing available right now; the caller reads again.
                    return '';
                }
            }

            if ($chunk !== '') {
                $this->position += \strlen($chunk);

                return $chunk;
            }
            $this->next();
        }

        return '';
    }

    public function getContents(): string
    {
        $contents = '';
        while (!$this->eof()) {
            $contents .= $this->read(65536);
        }

        return $contents;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMetadata(?string $key = null): ?array
    {
        return $key === null ? [] : null;
    }

    private function skipExhausted(): void
    {
        while ($this->index < \count($this->parts)) {
            $part = $this->parts[$this->index];
            $exhausted = \is_string($part) ? $this->offset >= \strlen($part) : $part->eof();
            if (!$exhausted) {
                return;
            }
            $this->next();
        }
    }

    private function next(): void
    {
        ++$this->index;
        $this->offset = 0;
    }
}
