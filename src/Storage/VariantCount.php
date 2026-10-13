<?php

namespace CaturSeptian\PennantAnalytics\Storage;

/**
 * Aggregated counts for one variant and action.
 */
final readonly class VariantCount
{
    public function __construct(
        public string $variant,
        public string $action,
        /** Unique subjects. */
        public int $uniques,
        /** Every hit, including repeats; null when not derivable (cohort queries). */
        public ?int $total,
    ) {}
}
