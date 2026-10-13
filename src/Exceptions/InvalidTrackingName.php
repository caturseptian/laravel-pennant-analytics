<?php

namespace CaturSeptian\PennantAnalytics\Exceptions;

use InvalidArgumentException;

/**
 * A feature or action name that cannot be stored. Always a programmer error,
 * so it is thrown from the tracking call instead of being swallowed.
 */
class InvalidTrackingName extends InvalidArgumentException
{
    public static function make(string $kind, string $value, int $max): self
    {
        return new self(sprintf(
            '%s name must be 1-%d characters, got %d: "%s".',
            $kind,
            $max,
            mb_strlen($value),
            mb_strimwidth($value, 0, 40, '...'),
        ));
    }
}
