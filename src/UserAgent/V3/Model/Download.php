<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\UserAgent\V3\Model;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * A binary file returned by the API (evidence XML/PDF, ZIP archive).
 *
 * The body is not read into memory by the SDK; copy {@see $stream} to its destination, e.g.
 * `while (!$d->stream->eof()) { fwrite($fh, $d->stream->read(65536)); }`, or use {@see saveTo()}.
 */
final readonly class Download
{
    public function __construct(
        public StreamInterface $stream,
        public ?string $contentType,
        public ?string $fileName,
        public ?int $size,
    ) {}

    /**
     * @internal
     */
    public static function fromResponse(ResponseInterface $response): self
    {
        $contentType = trim(explode(';', $response->getHeaderLine('Content-Type'))[0]);
        $length = $response->getHeaderLine('Content-Length');

        return new self(
            $response->getBody(),
            $contentType !== '' ? $contentType : null,
            self::fileName($response->getHeaderLine('Content-Disposition')),
            ctype_digit($length) ? (int) $length : null,
        );
    }

    /**
     * Streams the content into $path in 64 KiB chunks and returns the number of bytes written.
     */
    public function saveTo(string $path): int
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Unable to open "%s" for writing.', $path));
        }

        try {
            if ($this->stream->isSeekable()) {
                $this->stream->rewind();
            }
            $written = 0;
            while (!$this->stream->eof()) {
                $chunk = $this->stream->read(65536);
                if ($chunk === '') {
                    continue;
                }
                $result = fwrite($handle, $chunk);
                if ($result === false) {
                    throw new \RuntimeException(\sprintf('Writing to "%s" failed.', $path));
                }
                $written += $result;
            }

            return $written;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Extracts a safe base name from Content-Disposition (RFC 6266 filename* preferred); never a path.
     */
    private static function fileName(string $disposition): ?string
    {
        if ($disposition === '') {
            return null;
        }

        $name = null;
        if (preg_match("/filename\\*\\s*=\\s*[^']*'[^']*'([^;]+)/i", $disposition, $m) === 1) {
            $name = rawurldecode(trim($m[1]));
        } elseif (preg_match('/filename\s*=\s*"([^"]*)"/i', $disposition, $m) === 1 || preg_match('/filename\s*=\s*([^;]+)/i', $disposition, $m) === 1) {
            $name = trim($m[1]);
        }

        if ($name === null) {
            return null;
        }

        // Never trust server-supplied paths: keep only the last segment and drop control characters.
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        return $name === '' || $name === '.' || $name === '..' ? null : $name;
    }
}
