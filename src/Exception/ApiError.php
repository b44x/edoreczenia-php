<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * One error entry reported by an e-Doręczenia service, e.g. SE API "SEAPI-00007"
 * or an OAuth "invalid_client" error from the authorization server.
 */
final readonly class ApiError
{
    public function __construct(
        public string $code,
        public string $message,
        public ?string $language = null,
    ) {}
}
