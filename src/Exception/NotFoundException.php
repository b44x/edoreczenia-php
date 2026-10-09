<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * HTTP 404. The SE API also uses 404 for business outcomes such as SEAPI-00010 (no results).
 */
final class NotFoundException extends ApiException {}
