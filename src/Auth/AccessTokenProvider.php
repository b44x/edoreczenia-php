<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Auth;

use B4x\Edoreczenia\Exception\EdoreczeniaException;

/**
 * Supplies bearer tokens to API clients.
 *
 * The SDK ships {@see SignedJwtTokenProvider} (the documented system-to-system flow) and
 * {@see StaticTokenProvider}. Implement this interface to plug in a token obtained elsewhere,
 * for example from a central token broker shared by several workers.
 */
interface AccessTokenProvider
{
    /**
     * @throws EdoreczeniaException
     */
    public function getToken(): AccessToken;

    /**
     * Forget the current token, e.g. after the API answered 401.
     */
    public function invalidate(): void;
}
