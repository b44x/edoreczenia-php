<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Auth;

use B4x\Edoreczenia\Exception\InvalidArgumentException;

/**
 * Identity of a system (e.g. an EZD) added in the permission module of an e-Doręczenia mailbox.
 *
 * The client identifier used as "iss"/"sub" of the client assertion is
 * "{deliveryAddress}.SYSTEM.{systemName}", as documented in the integration instructions.
 */
final class SystemCredentials
{
    public readonly string $deliveryAddress;
    public readonly string $systemName;

    /**
     * @param string $deliveryAddress the mailbox address (ADE), e.g. "AE:PL-12345-67890-ABCDE-12"
     * @param string $systemName      the name given to the system when it was added to the mailbox
     */
    public function __construct(
        string $deliveryAddress,
        string $systemName,
        public readonly PrivateKey $privateKey,
    ) {
        $this->deliveryAddress = self::assertToken($deliveryAddress, 'Delivery address');
        $this->systemName = self::assertToken($systemName, 'System name');
    }

    public static function fromPemFile(string $deliveryAddress, string $systemName, string $privateKeyPath, #[\SensitiveParameter] ?string $passphrase = null): self
    {
        return new self($deliveryAddress, $systemName, PrivateKey::fromFile($privateKeyPath, $passphrase));
    }

    public function clientId(): string
    {
        return $this->deliveryAddress . '.SYSTEM.' . $this->systemName;
    }

    private static function assertToken(string $value, string $label): string
    {
        if ($value === '' || preg_match('/\s/u', $value) === 1) {
            throw new InvalidArgumentException(\sprintf('%s must be a non-empty string without whitespace.', $label));
        }

        return $value;
    }
}
