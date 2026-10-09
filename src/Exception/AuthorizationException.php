<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * The system is authenticated but not permitted to perform the operation (HTTP 403).
 */
final class AuthorizationException extends ApiException {}
