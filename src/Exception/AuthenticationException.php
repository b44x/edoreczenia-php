<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * Authentication failed: the token request was rejected or the access token was not accepted (HTTP 401).
 */
final class AuthenticationException extends ApiException {}
