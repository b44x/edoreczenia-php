<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Cli;

/**
 * Generates the RSA 2048 private key and the PKCS#10 certificate signing request (CSR)
 * uploaded by the mailbox administrator in the permission module.
 *
 * Subject rules follow the official instruction ("Załącznik 1c", section 3.3): C is a
 * two-letter country code, O is the full company name (a shortened name of at most
 * 64 characters is allowed), CN is the domain name (a wildcard is allowed), and all
 * values are entered without Polish diacritics and without commas.
 *
 * @internal
 */
final class KeygenCommand
{
    public const OPTIONS = ['out-dir', 'country', 'state', 'locality', 'organization', 'unit', 'common-name', 'email', 'name', 'passphrase-env'];

    private const KEY_BITS = 2048;
    private const MAX_ORGANIZATION_LENGTH = 64;
    private const MAX_COMMON_NAME_LENGTH = 64;
    private const DIACRITICS = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        'Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z',
    ];

    /**
     * @param \Closure(string): ?string $readEnv
     */
    public function __construct(
        private readonly Output $out,
        private readonly \Closure $readEnv,
    ) {}

    public function run(Arguments $args): int
    {
        $args->assertOnly(self::OPTIONS);

        $outDir = $args->required('out-dir');
        $subject = $this->subject($args);
        $baseName = $args->optional('name', 'ezd') ?? 'ezd';
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $baseName) !== 1) {
            throw new UsageException('Option --name may contain only letters, digits, ".", "_" and "-".');
        }
        $passphrase = self::passphrase($args, $this->readEnv);

        if (!is_dir($outDir) || !is_writable($outDir)) {
            return $this->fail(\sprintf('Output directory "%s" does not exist or is not writable.', $outDir));
        }

        $keyPath = rtrim($outDir, '/\\') . \DIRECTORY_SEPARATOR . $baseName . '.key';
        $csrPath = rtrim($outDir, '/\\') . \DIRECTORY_SEPARATOR . $baseName . '.csr';
        foreach ([$keyPath, $csrPath] as $path) {
            if (file_exists($path)) {
                return $this->fail(\sprintf('"%s" already exists; refusing to overwrite it.', $path));
            }
        }

        try {
            [$keyPem, $csrPem, $publicPem] = self::generate($subject, $passphrase);
            self::writeNew($keyPath, $keyPem, 0o600);
            self::writeNew($csrPath, $csrPem, 0o644);
            self::verify($keyPath, $csrPath, $passphrase, $publicPem);
        } catch (\RuntimeException $e) {
            self::drainOpenSslErrors();

            return $this->fail($e->getMessage());
        }

        $this->out->line('Private key: ' . $keyPath . ' (RSA ' . self::KEY_BITS . ', mode 0600' . ($passphrase !== null ? ', encrypted' : '') . ')');
        $this->out->line('CSR:         ' . $csrPath);
        $this->out->line('Subject:     ' . self::formatSubject($subject));
        $this->out->line('Public key SHA-256 fingerprint:');
        $this->out->line('  ' . self::fingerprint($publicPem));
        $this->out->line();
        $this->out->line('Next steps:');
        $this->out->line('  1. Give ' . $baseName . '.csr to the mailbox administrator, who uploads it in the permission module');
        $this->out->line('     of the mailbox, choosing the option "żądanie certyfikatu".');
        $this->out->line('  2. Keep ' . $baseName . '.key private: do not send it to anyone, do not commit it, back it up securely.');
        $this->out->line('  3. Check the setup: vendor/bin/edoreczenia doctor --env=int --address=ADE --system=NAME --key=' . $keyPath);

        return Application::EXIT_OK;
    }

    /**
     * @param \Closure(string): ?string $readEnv
     */
    public static function passphrase(Arguments $args, \Closure $readEnv): ?string
    {
        $variable = $args->optional('passphrase-env');
        if ($variable === null) {
            return null;
        }

        $value = $readEnv($variable);
        if ($value === null || $value === '') {
            throw new UsageException(\sprintf('Environment variable %s (--passphrase-env) is not set or empty.', $variable));
        }

        return $value;
    }

    /**
     * @return array<string, string> OpenSSL distinguished name, in subject order
     */
    private function subject(Arguments $args): array
    {
        $fields = [
            'countryName' => ['country', $args->optional('country', 'PL') ?? 'PL'],
            'stateOrProvinceName' => ['state', $args->required('state')],
            'localityName' => ['locality', $args->required('locality')],
            'organizationName' => ['organization', $args->required('organization')],
            'organizationalUnitName' => ['unit', $args->optional('unit')],
            'commonName' => ['common-name', $args->required('common-name')],
            'emailAddress' => ['email', $args->optional('email')],
        ];

        $subject = [];
        foreach ($fields as $key => [$option, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $value = trim($value);
            self::assertPlainText($option, $value);
            $subject[$key] = $value;
        }

        if (preg_match('/^[A-Z]{2}$/', $subject['countryName'] ?? '') !== 1) {
            throw new UsageException('Option --country must be a two-letter uppercase country code, e.g. PL.');
        }

        $organization = $subject['organizationName'] ?? '';
        if (mb_strlen($organization) > self::MAX_ORGANIZATION_LENGTH) {
            throw new UsageException(\sprintf(
                'Option --organization is %d characters long; at most %d are allowed. Use a shortened company name.',
                mb_strlen($organization),
                self::MAX_ORGANIZATION_LENGTH,
            ));
        }

        $commonName = $subject['commonName'] ?? '';
        if (\strlen($commonName) > self::MAX_COMMON_NAME_LENGTH
            || preg_match('/^(\*\.)?([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)+[A-Za-z]{2,63}$/', $commonName) !== 1) {
            throw new UsageException('Option --common-name must be a domain name such as ezd.domena.pl or a wildcard such as *.domena.pl.');
        }

        if (isset($subject['emailAddress']) && filter_var($subject['emailAddress'], \FILTER_VALIDATE_EMAIL) === false) {
            throw new UsageException('Option --email is not a valid e-mail address.');
        }

        return $subject;
    }

    private static function assertPlainText(string $option, string $value): void
    {
        if (str_contains($value, ',')) {
            throw new UsageException(\sprintf('Option --%s must not contain commas (instruction "Załącznik 1c", section 3.3). Suggested: "%s".', $option, self::suggest($value)));
        }

        if (strtr($value, self::DIACRITICS) !== $value) {
            throw new UsageException(\sprintf('Option --%s must not contain Polish diacritics (instruction "Załącznik 1c", section 3.3). Suggested: "%s".', $option, self::suggest($value)));
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new UsageException(\sprintf('Option --%s must not contain control characters.', $option));
        }
    }

    private static function suggest(string $value): string
    {
        $plain = strtr(str_replace(',', '', $value), self::DIACRITICS);

        return trim((string) preg_replace('/\s+/', ' ', $plain));
    }

    /**
     * @param array<string, string> $subject
     *
     * @return array{string, string, string} private key PEM, CSR PEM, public key PEM
     */
    private static function generate(array $subject, ?string $passphrase): array
    {
        $key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_RSA, 'private_key_bits' => self::KEY_BITS]);
        if ($key === false) {
            throw new \RuntimeException('OpenSSL could not generate the RSA key.');
        }

        // openssl_csr_new() takes the key by reference; keep $key itself untouched
        $signingKey = $key;
        $csr = openssl_csr_new($subject, $signingKey, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            throw new \RuntimeException('OpenSSL could not create the CSR.');
        }

        $csrPem = '';
        $keyPem = '';
        $exportOptions = $passphrase !== null ? ['encrypt_key' => true, 'encrypt_key_cipher' => \OPENSSL_CIPHER_AES_256_CBC] : [];
        if (!openssl_csr_export($csr, $csrPem) || !openssl_pkey_export($key, $keyPem, $passphrase, $exportOptions)) {
            throw new \RuntimeException('OpenSSL could not export the key or the CSR.');
        }

        $details = openssl_pkey_get_details($key);
        if ($details === false || !\is_string($details['key'] ?? null) || !\is_string($keyPem) || !\is_string($csrPem)) {
            throw new \RuntimeException('OpenSSL could not read the generated key.');
        }

        return [$keyPem, $csrPem, $details['key']];
    }

    /**
     * Creates the file exclusively ("x" mode) and restricts its mode before any content is written.
     */
    private static function writeNew(string $path, string $content, int $mode): void
    {
        $handle = @fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException(\sprintf('Unable to create "%s" (it may already exist).', $path));
        }

        try {
            if (!chmod($path, $mode) || fwrite($handle, $content) !== \strlen($content)) {
                throw new \RuntimeException(\sprintf('Unable to write "%s".', $path));
            }
        } finally {
            fclose($handle);
        }
    }

    private static function verify(string $keyPath, string $csrPath, ?string $passphrase, string $publicPem): void
    {
        $csrPem = file_get_contents($csrPath);
        $keyPem = file_get_contents($keyPath);
        if ($csrPem === false || $keyPem === false) {
            throw new \RuntimeException('Unable to read back the written files.');
        }

        if (openssl_csr_get_subject($csrPem) === false) {
            throw new \RuntimeException(\sprintf('Verification failed: "%s" is not a parsable CSR.', $csrPath));
        }

        $csrKey = openssl_csr_get_public_key($csrPem);
        $privateKey = openssl_pkey_get_private($keyPem, $passphrase);
        $csrDetails = $csrKey === false ? false : openssl_pkey_get_details($csrKey);
        $keyDetails = $privateKey === false ? false : openssl_pkey_get_details($privateKey);

        if ($csrDetails === false || $keyDetails === false
            || ($csrDetails['key'] ?? null) !== $publicPem || ($keyDetails['key'] ?? null) !== $publicPem) {
            throw new \RuntimeException('Verification failed: the public key in the CSR does not match the private key.');
        }

        self::drainOpenSslErrors();
    }

    /**
     * SHA-256 of the DER-encoded SubjectPublicKeyInfo, as colon-separated hex.
     */
    private static function fingerprint(string $publicPem): string
    {
        $base64 = preg_replace('/-----[^-]+-----|\s+/', '', $publicPem) ?? '';
        $der = base64_decode($base64, true);
        if ($der === false) {
            return '(unavailable)';
        }

        return implode(':', str_split(strtoupper(hash('sha256', $der)), 2));
    }

    /**
     * @param array<string, string> $subject
     */
    private static function formatSubject(array $subject): string
    {
        $short = [
            'countryName' => 'C', 'stateOrProvinceName' => 'ST', 'localityName' => 'L', 'organizationName' => 'O',
            'organizationalUnitName' => 'OU', 'commonName' => 'CN', 'emailAddress' => 'emailAddress',
        ];

        $parts = [];
        foreach ($subject as $key => $value) {
            $parts[] = '/' . ($short[$key] ?? $key) . '=' . $value;
        }

        return implode('', $parts);
    }

    private function fail(string $message): int
    {
        $this->out->line('[FAIL] ' . $message);

        return Application::EXIT_FAILURE;
    }

    private static function drainOpenSslErrors(): void
    {
        while (openssl_error_string() !== false) {
            // OpenSSL error details are not needed and must not pile up between calls
        }
    }
}
