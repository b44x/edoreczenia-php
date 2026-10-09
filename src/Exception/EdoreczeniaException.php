<?php

declare(strict_types=1);

namespace B4x\Edoreczenia\Exception;

/**
 * Marker implemented by every exception thrown by this SDK.
 *
 * Catch this interface to handle all SDK failures in one place.
 */
interface EdoreczeniaException extends \Throwable {}
