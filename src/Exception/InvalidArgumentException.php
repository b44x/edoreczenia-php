<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * A value passed to the SDK was rejected locally, before any network call was made.
 */
final class InvalidArgumentException extends \InvalidArgumentException implements EdoreczeniaException {}
