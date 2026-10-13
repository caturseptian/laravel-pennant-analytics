<?php

namespace CaturSeptian\PennantAnalytics\Analytics;

use CaturSeptian\PennantAnalytics\Statistics\ZTestResult;

final readonly class VariantResult
{
    public function __construct(
        public string $variant,
        /** Unique subjects exposed to this variant. */
        public int $impressions,
        /** Unique subjects who performed the conversion action. */
        public int $conversions,
        /** Every conversion event, including repeats by the same subject. */
        public int $totalConversions,
        public bool $isControl,
        /** Comparison against the control; null for the control itself. */
        public ?ZTestResult $comparison = null,
    ) {}

    /**
     * Conversion rate in percent: (Conversions / Impressions) * 100.
     */
    public function conversionRate(): float
    {
        return self::rate($this->conversions, $this->impressions);
    }

    public static function rate(int $conversions, int $impressions): float
    {
        return $impressions > 0 ? round($conversions / $impressions * 100, 2) : 0.0;
    }
}
