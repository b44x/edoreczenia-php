<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * No HTTP client was passed and none of the supported PSR-18 clients or PSR-17 factories
 * is installed. Install one (e.g. `composer require guzzlehttp/guzzle`) or pass your own.
 */
final class HttpClientNotFoundException extends \RuntimeException implements EdoreczeniaException {}
