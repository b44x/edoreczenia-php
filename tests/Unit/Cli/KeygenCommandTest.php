<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Tests\Unit\Cli;

use B4x\Edoreczenia\Auth\PrivateKey;
use B4x\Edoreczenia\Cli\Application;
use B4x\Edoreczenia\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class KeygenCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'edoreczenia-keygen-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700);
    }

    protected function tearDown(): void
    {
        $files = glob($this->dir . '/*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    public function testGeneratesKeyAndMatchingCsr(): void
    {
        [$code, $out, $err] = $this->keygen([...$this->validOptions(), '--unit=IT', '--email=admin@example.test']);

        self::assertSame(0, $code, $out . $err);
        self::assertSame('', $err);

        $keyPath = $this->dir . '/ezd.key';
        $csrPath = $this->dir . '/ezd.csr';
        self::assertFileExists($keyPath);
        self::assertFileExists($csrPath);
        self::assertSame(0o600, fileperms($keyPath) & 0o777);

        $csrPem = (string) file_get_contents($csrPath);
        self::assertStringStartsWith('-----BEGIN CERTIFICATE REQUEST-----', $csrPem);
        self::assertSame([
            'C' => 'PL',
            'ST' => 'Mazowieckie',
            'L' => 'Warszawa',
            'O' => 'Firma Testowa',
            'OU' => 'IT',
            'CN' => 'ezd.example.test',
            'emailAddress' => 'admin@example.test',
        ], openssl_csr_get_subject($csrPem, true));

        $private = openssl_pkey_get_private((string) file_get_contents($keyPath));
        $csrKey = openssl_csr_get_public_key($csrPem);
        self::assertNotFalse($private);
        self::assertNotFalse($csrKey);
        $privateDetails = openssl_pkey_get_details($private);
        $csrDetails = openssl_pkey_get_details($csrKey);
        self::assertNotFalse($privateDetails);
        self::assertNotFalse($csrDetails);
        self::assertSame($privateDetails['key'], $csrDetails['key']);
        self::assertSame(2048, $privateDetails['bits']);

        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', \is_string($csrDetails['key']) ? $csrDetails['key'] : ''), true);
        self::assertIsString($der);
        self::assertStringContainsString(implode(':', str_split(strtoupper(hash('sha256', $der)), 2)), $out);
        self::assertStringContainsString('/C=PL/ST=Mazowieckie/L=Warszawa/O=Firma Testowa/OU=IT/CN=ezd.example.test', $out);
        self::assertStringContainsString('żądanie certyfikatu', $out);
        self::assertStringNotContainsString('PRIVATE KEY', $out);
        self::assertStringNotContainsString('CERTIFICATE REQUEST', $out);
    }

    public function testRefusesToOverwriteExistingFiles(): void
    {
        file_put_contents($this->dir . '/ezd.csr', 'existing');

        [$code, $out] = $this->keygen($this->validOptions());

        self::assertSame(1, $code);
        self::assertStringContainsString('refusing to overwrite', $out);
        self::assertFileDoesNotExist($this->dir . '/ezd.key');
        self::assertSame('existing', file_get_contents($this->dir . '/ezd.csr'));
    }

    public function testSecondRunDoesNotReplaceTheKey(): void
    {
        self::assertSame(0, $this->keygen($this->validOptions())[0]);
        $key = file_get_contents($this->dir . '/ezd.key');

        self::assertSame(1, $this->keygen($this->validOptions())[0]);
        self::assertSame($key, file_get_contents($this->dir . '/ezd.key'));
    }

    public function testUsesCustomBaseName(): void
    {
        self::assertSame(0, $this->keygen([...$this->validOptions(), '--name=system-1'])[0]);

        self::assertFileExists($this->dir . '/system-1.key');
        self::assertFileExists($this->dir . '/system-1.csr');
    }

    public function testEncryptsKeyWithPassphraseFromEnvironment(): void
    {
        $env = static fn(string $name): ?string => $name === 'EZD_KEY_PASS' ? 'correct horse battery staple' : null;

        [$code, $out] = $this->keygen([...$this->validOptions(), '--passphrase-env=EZD_KEY_PASS'], $env);

        self::assertSame(0, $code, $out);
        $pem = (string) file_get_contents($this->dir . '/ezd.key');
        self::assertStringContainsString('ENCRYPTED', $pem);
        self::assertSame(2048, PrivateKey::fromPem($pem, 'correct horse battery staple')->bits());
        self::assertStringNotContainsString('correct horse', $out);

        $this->expectException(InvalidArgumentException::class);
        PrivateKey::fromPem($pem, 'wrong passphrase');
    }

    public function testMissingPassphraseVariableIsUsageError(): void
    {
        [$code, , $err] = $this->keygen([...$this->validOptions(), '--passphrase-env=NOT_SET']);

        self::assertSame(2, $code);
        self::assertStringContainsString('NOT_SET', $err);
        self::assertFileDoesNotExist($this->dir . '/ezd.key');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSubjects(): iterable
    {
        yield 'diacritics in locality' => ['--locality=Kraków', 'Option --locality must not contain Polish diacritics'];
        yield 'diacritics suggestion' => ['--state=Śląskie', 'Suggested: "Slaskie"'];
        yield 'comma in organization' => ['--organization=Firma, Sp. z o.o.', 'Option --organization must not contain commas'];
        yield 'organization too long' => ['--organization=' . str_repeat('A', 65), 'at most 64 are allowed'];
        yield 'lowercase country' => ['--country=pl', 'two-letter uppercase country code'];
        yield 'three-letter country' => ['--country=POL', 'two-letter uppercase country code'];
        yield 'common name with scheme' => ['--common-name=https://ezd.example.test', 'Option --common-name must be a domain name'];
        yield 'common name without dot' => ['--common-name=localhost', 'Option --common-name must be a domain name'];
        yield 'common name with space' => ['--common-name=ezd example.test', 'Option --common-name must be a domain name'];
        yield 'invalid email' => ['--email=not-an-email', 'Option --email is not a valid e-mail address'];
        yield 'bad file name' => ['--name=../ezd', 'Option --name may contain only'];
    }

    #[DataProvider('invalidSubjects')]
    public function testRejectsInvalidSubject(string $option, string $expected): void
    {
        $name = substr($option, 0, (int) strpos($option, '='));
        $options = array_values(array_filter($this->validOptions(), static fn(string $o): bool => !str_starts_with($o, $name . '=')));

        [$code, $out, $err] = $this->keygen([...$options, $option]);

        self::assertSame(2, $code, $out);
        self::assertStringContainsString($expected, $err);
        self::assertSame([], glob($this->dir . '/*'));
    }

    public function testAcceptsWildcardCommonName(): void
    {
        $options = array_values(array_filter($this->validOptions(), static fn(string $o): bool => !str_starts_with($o, '--common-name=')));

        self::assertSame(0, $this->keygen([...$options, '--common-name=*.example.test'])[0]);
    }

    public function testRequiredOptionsAreEnforced(): void
    {
        [$code, , $err] = $this->keygen(['--out-dir=' . $this->dir, '--state=Mazowieckie']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Option --locality is required', $err);
    }

    public function testMissingDirectoryFails(): void
    {
        $options = array_values(array_filter($this->validOptions(), static fn(string $o): bool => !str_starts_with($o, '--out-dir=')));

        [$code, $out] = $this->keygen([...$options, '--out-dir=' . $this->dir . '/missing']);

        self::assertSame(1, $code);
        self::assertStringContainsString('does not exist', $out);
    }

    public function testUnknownOptionIsUsageError(): void
    {
        [$code, , $err] = $this->keygen([...$this->validOptions(), '--bits=4096']);

        self::assertSame(2, $code);
        self::assertStringContainsString('Unknown option --bits', $err);
    }

    /**
     * @return list<string>
     */
    private function validOptions(): array
    {
        return [
            '--out-dir=' . $this->dir,
            '--state=Mazowieckie',
            '--locality=Warszawa',
            '--organization=Firma Testowa',
            '--common-name=ezd.example.test',
        ];
    }

    /**
     * @param list<string>                     $options
     * @param (\Closure(string): ?string)|null $env
     *
     * @return array{int, string, string}
     */
    private function keygen(array $options, ?\Closure $env = null): array
    {
        $argv = ['edoreczenia', 'keygen', ...$options];
        $env ??= static fn(string $name): ?string => null;

        $stdout = fopen('php://memory', 'w+');
        $stderr = fopen('php://memory', 'w+');
        self::assertIsResource($stdout);
        self::assertIsResource($stderr);

        $code = (new Application($stdout, $stderr, readEnv: $env))->run($argv);

        rewind($stdout);
        rewind($stderr);

        return [$code, (string) stream_get_contents($stdout), (string) stream_get_contents($stderr)];
    }
}
