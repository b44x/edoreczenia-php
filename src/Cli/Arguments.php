<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Cli;

/**
 * Minimal parser for "--option=value", "--option value" and "--help".
 *
 * Positional arguments other than the command name are rejected, as are unknown
 * and repeated options, so a typo never silently falls back to a default.
 *
 * @internal
 */
final readonly class Arguments
{
    /**
     * @param array<string, string> $options
     */
    private function __construct(
        public ?string $command,
        public bool $help,
        private array $options,
    ) {}

    /**
     * @param list<string> $argv arguments without the script name
     */
    public static function parse(array $argv): self
    {
        $command = null;
        $help = false;
        $options = [];

        for ($i = 0, $count = \count($argv); $i < $count; ++$i) {
            $arg = $argv[$i];

            if ($arg === '--help' || $arg === '-h') {
                $help = true;

                continue;
            }

            if (!str_starts_with($arg, '--')) {
                if ($command !== null) {
                    throw new UsageException(\sprintf('Unexpected argument "%s".', $arg));
                }
                $command = $arg;

                continue;
            }

            $eq = strpos($arg, '=');
            if ($eq !== false) {
                $name = substr($arg, 2, $eq - 2);
                $value = substr($arg, $eq + 1);
            } else {
                $name = substr($arg, 2);
                $next = $argv[$i + 1] ?? null;
                if ($next === null || str_starts_with($next, '--')) {
                    throw new UsageException(\sprintf('Option --%s requires a value.', $name));
                }
                $value = $next;
                ++$i;
            }

            if ($name === '' || preg_match('/^[a-z][a-z-]*$/', $name) !== 1) {
                throw new UsageException(\sprintf('Malformed option "%s".', $arg));
            }
            if (\array_key_exists($name, $options)) {
                throw new UsageException(\sprintf('Option --%s given more than once.', $name));
            }

            $options[$name] = $value;
        }

        return new self($command, $help, $options);
    }

    /**
     * @param list<string> $allowed
     */
    public function assertOnly(array $allowed): void
    {
        foreach (array_keys($this->options) as $name) {
            if (!\in_array($name, $allowed, true)) {
                throw new UsageException(\sprintf('Unknown option --%s for "%s".', $name, $this->command ?? ''));
            }
        }
    }

    public function optional(string $name, ?string $default = null): ?string
    {
        return $this->options[$name] ?? $default;
    }

    public function required(string $name): string
    {
        $value = $this->options[$name] ?? null;
        if ($value === null || trim($value) === '') {
            throw new UsageException(\sprintf('Option --%s is required.', $name));
        }

        return $value;
    }
}
