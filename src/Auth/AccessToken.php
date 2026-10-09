<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Auth;

use B4x\Edoreczenia\Exception\InvalidArgumentException;

/**
 * Bearer access token issued by the authorization server.
 *
 * The token value is a credential: it is excluded from var_dump()/print_r() output.
 */
final class AccessToken
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $value,
        public readonly \DateTimeImmutable $expiresAt,
    ) {
        if ($value === '') {
            throw new InvalidArgumentException('Access token must not be empty.');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    /**
     * True when the token is still valid for at least $leewaySeconds after $now.
     */
    public function isUsableAt(\DateTimeImmutable $now, int $leewaySeconds = 0): bool
    {
        return $now->getTimestamp() + $leewaySeconds < $this->expiresAt->getTimestamp();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]', 'expiresAt' => $this->expiresAt->format(\DATE_ATOM)];
    }
}
