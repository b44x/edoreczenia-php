<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Cli;

/**
 * Line-oriented writer over a stream resource (STDOUT, STDERR or php://memory in tests).
 *
 * @internal
 */
final class Output
{
    /**
     * @param resource $stream
     */
    public function __construct(private $stream) {}

    public function line(string $text = ''): void
    {
        fwrite($this->stream, $text . \PHP_EOL);
    }
}
