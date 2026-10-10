<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Cli;

use B4x\Edoreczenia\Cli\Application;
use B4x\Edoreczenia\Http\HttpStack;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function helpInvocations(): iterable
    {
        yield 'no arguments' => [[]];
        yield 'help command' => [['help']];
        yield 'help option' => [['--help']];
        yield 'short help option' => [['-h']];
        yield 'command help' => [['doctor', '--help']];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('helpInvocations')]
    public function testPrintsUsage(array $args): void
    {
        [$code, $out, $err] = $this->execute($args);

        self::assertSame(0, $code);
        self::assertSame('', $err);
        self::assertStringContainsString('edoreczenia keygen --out-dir=DIR', $out);
        self::assertStringContainsString('edoreczenia doctor --env=int|prod', $out);
        self::assertStringContainsString('application to COI', $out);
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function usageErrors(): iterable
    {
        yield 'unknown command' => [['deploy'], 'Unknown command "deploy"'];
        yield 'extra positional argument' => [['keygen', 'extra'], 'Unexpected argument "extra"'];
        yield 'option without value' => [['keygen', '--out-dir'], 'Option --out-dir requires a value'];
        yield 'option followed by option' => [['keygen', '--out-dir', '--state=X'], 'Option --out-dir requires a value'];
        yield 'repeated option' => [['doctor', '--env=int', '--env=prod'], 'Option --env given more than once'];
        yield 'malformed option' => [['doctor', '--=x'], 'Malformed option'];
        yield 'unknown doctor option' => [['doctor', '--token=abc'], 'Unknown option --token'];
    }

    /**
     * @param list<string> $args
     */
    #[DataProvider('usageErrors')]
    public function testUsageErrorsExitWithTwo(array $args, string $expected): void
    {
        [$code, $out, $err] = $this->execute($args);

        self::assertSame(2, $code);
        self::assertSame('', $out);
        self::assertStringContainsString($expected, $err);
        self::assertStringContainsString('edoreczenia help', $err);
    }

    public function testAcceptsSpaceSeparatedOptionValues(): void
    {
        [$code, , $err] = $this->execute(['doctor', '--env', 'int', '--address', 'AE:PL-1', '--system', 'EZD1']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Option --key is required', $err);
    }

    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private function execute(array $args): array
    {
        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $noHttp = static fn(int $timeout): HttpStack => throw new \LogicException('No HTTP expected.');
        $code = (new Application($stdout, $stderr, $noHttp, readEnv: static fn(string $name): ?string => null))->run(['edoreczenia', ...$args]);

        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
