<?php

namespace CaturSeptian\PennantAnalytics\Support;

use BackedEnum;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Normalizes any Pennant feature value into a stable string label.
 *
 * Pennant values may be booleans (classic on/off flags), strings or ints
 * (multivariate "rich values"), enums, or even arrays. Analytics need one
 * comparable label per variant, so we map them deterministically:
 *
 *   true -> "true", false -> "false", null -> "null", 'blue' -> "blue",
 *   Color::Blue -> "blue", ['a' => 1] -> '{"a":1}'
 */
final class Variant
{
    public const MAX_LENGTH = 100;

    public static function label(mixed $value): string
    {
        $label = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            is_scalar($value), $value instanceof Stringable => (string) $value,
            is_array($value), $value instanceof JsonSerializable => (string) json_encode($value),
            default => get_debug_type($value),
        };

        // Oversized labels (big JSON payloads) are digested so they still fit
        // the indexed column while remaining unique per distinct value.
        return mb_strlen($label) > self::MAX_LENGTH
            ? mb_substr($label, 0, self::MAX_LENGTH - 33).'~'.hash('xxh128', $label)
            : $label;
    }
}
