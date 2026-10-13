<?php

namespace CaturSeptian\PennantAnalytics\Analytics;

use CaturSeptian\PennantAnalytics\Statistics\TwoProportionZTest;
use CaturSeptian\PennantAnalytics\Storage\VariantCount;
use CaturSeptian\PennantAnalytics\Support\AnalyticsConfig;

/**
 * Conversion report for one feature + one conversion action.
 *
 * Every non-control variant is compared against the control with a
 * two-proportion z-test. With more than two variants each comparison is run
 * at a Bonferroni-corrected confidence level, so the chance of a false
 * "winner" stays at the configured level overall.
 */
final readonly class ExperimentReport
{
    /**
     * @param  list<VariantResult>  $variants
     */
    public function __construct(
        public string $feature,
        public string $action,
        public array $variants,
        /** Overall confidence level, e.g. 0.95. */
        public float $confidence,
        /** Number of challenger-vs-control comparisons (Bonferroni divisor). */
        public int $comparisons = 1,
    ) {}

    /**
     * The p-value a single comparison must beat: (1 - confidence) / comparisons.
     */
    public function threshold(): float
    {
        return (1 - $this->confidence) / max(1, $this->comparisons);
    }

    /**
     * e.g. "p < 0.025 (95% confidence, Bonferroni-corrected for 2 comparisons)".
     */
    public function thresholdDescription(): string
    {
        $confidence = rtrim(rtrim(number_format($this->confidence * 100, 2), '0'), '.');
        $threshold = rtrim(rtrim(number_format($this->threshold(), 4), '0'), '.');

        return $this->comparisons > 1
            ? "p < {$threshold} ({$confidence}% confidence, Bonferroni-corrected for {$this->comparisons} comparisons)"
            : "p < {$threshold} ({$confidence}% confidence)";
    }

    /**
     * @param  list<VariantCount>  $counts
     */
    public static function build(string $feature, string $action, array $counts, AnalyticsConfig $config): self
    {
        /** @var array<string, VariantCount> $impressions */
        $impressions = [];
        /** @var array<string, VariantCount> $conversions */
        $conversions = [];

        foreach ($counts as $count) {
            match ($count->action) {
                $config->impressionAction => $impressions[$count->variant] = $count,
                $action => $conversions[$count->variant] = $count,
                default => null,
            };
        }

        // Cohort (date range) counts carry no event totals.
        $isCohort = array_filter($counts, fn (VariantCount $count) => $count->total === null) !== [];

        $labels = array_values(array_unique([...array_map(strval(...), array_keys($impressions)), ...array_map(strval(...), array_keys($conversions))]));
        sort($labels, SORT_STRING);

        $control = self::pickControl($labels, $impressions, $config->controlVariants);

        $test = new TwoProportionZTest(
            confidence: 1 - (1 - $config->confidence) / max(1, count($labels) - 1),
            minSampleSize: $config->minSampleSize,
        );

        $controlImpressions = $control !== null ? ($impressions[$control]->uniques ?? 0) : 0;
        $controlConversions = $control !== null ? min($controlImpressions, $conversions[$control]->uniques ?? 0) : 0;

        $variants = array_map(function (string $label) use ($impressions, $conversions, $control, $test, $controlImpressions, $controlConversions, $isCohort) {
            $n = $impressions[$label]->uniques ?? 0;
            // Clamp guards against rows written outside the engine.
            $x = min($n, $conversions[$label]->uniques ?? 0);

            return new VariantResult(
                variant: $label,
                impressions: $n,
                conversions: $x,
                totalConversions: isset($conversions[$label]) ? $conversions[$label]->total : ($isCohort ? null : 0),
                isControl: $label === $control,
                comparison: $label === $control ? null : $test->compare($controlConversions, $controlImpressions, $x, $n),
            );
        }, $labels);

        // Control first, then best performers; labels break ties (stable sort).
        usort($variants, fn (VariantResult $a, VariantResult $b) => [$b->isControl, $b->conversionRate()] <=> [$a->isControl, $a->conversionRate()]);

        return new self($feature, $action, $variants, $config->confidence, max(1, count($labels) - 1));
    }

    public function control(): ?VariantResult
    {
        foreach ($this->variants as $variant) {
            if ($variant->isControl) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * The challenger with the best conversion rate (may still be losing).
     */
    public function leader(): ?VariantResult
    {
        $best = null;

        foreach ($this->variants as $variant) {
            if (! $variant->isControl && ($best === null || $variant->conversionRate() > $best->conversionRate())) {
                $best = $variant;
            }
        }

        return $best;
    }

    public function hasSignificantWinner(): bool
    {
        $comparison = $this->leader()?->comparison;

        return $comparison !== null && $comparison->isSignificant() && $comparison->isWinning();
    }

    /**
     * Human readable verdict, e.g.
     * "Variant TRUE is outperforming Variant FALSE by 23.5% (Statistically Significant)".
     */
    public function summary(): string
    {
        $control = $this->control();
        $leader = $this->leader();

        if ($control === null || $leader === null) {
            return 'Not enough variants to compare yet.';
        }

        $result = $leader->comparison;

        if ($result === null) {
            return 'Not enough variants to compare yet.';
        }

        $name = fn (VariantResult $v) => 'Variant '.mb_strtoupper($v->variant);

        if ($result->relativeLift === null) {
            return sprintf('%s has no conversions yet, so relative lift cannot be computed.', $name($control));
        }

        if (abs($result->relativeLift) < 0.005) {
            return sprintf('%s and %s are performing identically.', $name($leader), $name($control));
        }

        $verdict = match (true) {
            ! $result->hasEnoughData => 'Insufficient data',
            $result->isSignificant() => 'Statistically Significant',
            default => 'Not Statistically Significant',
        };

        return sprintf(
            '%s is %s %s by %s%% (%s)',
            $name($leader),
            $result->isWinning() ? 'outperforming' : 'underperforming',
            $name($control),
            number_format(abs($result->relativeLift), 1),
            $verdict,
        );
    }

    /**
     * First configured control label present, else the most exposed variant
     * (ties broken alphabetically, so the choice is deterministic).
     *
     * @param  list<string>  $labels  sorted
     * @param  array<string, VariantCount>  $impressions
     * @param  list<string>  $preferred
     */
    private static function pickControl(array $labels, array $impressions, array $preferred): ?string
    {
        foreach ($preferred as $candidate) {
            if (in_array($candidate, $labels, true)) {
                return $candidate;
            }
        }

        $control = null;

        foreach ($labels as $label) {
            if ($control === null || ($impressions[$label]->uniques ?? 0) > ($impressions[$control]->uniques ?? 0)) {
                $control = $label;
            }
        }

        return $control;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'feature' => $this->feature,
            'action' => $this->action,
            'confidence' => $this->confidence,
            'threshold' => round($this->threshold(), 6),
            'summary' => $this->summary(),
            'significant' => $this->hasSignificantWinner(),
            'variants' => array_map(fn (VariantResult $v) => [
                'variant' => $v->variant,
                'control' => $v->isControl,
                'impressions' => $v->impressions,
                'conversions' => $v->conversions,
                'total_conversions' => $v->totalConversions,
                'conversion_rate' => $v->conversionRate(),
                'relative_lift' => $v->comparison?->relativeLift !== null ? round($v->comparison->relativeLift, 2) : null,
                'z_score' => $v->comparison ? round($v->comparison->zScore, 4) : null,
                'p_value' => $v->comparison ? round($v->comparison->pValue, 6) : null,
                'significant' => $v->comparison?->isSignificant(),
                'result' => $v->comparison?->verdict() ?? 'baseline',
            ], $this->variants),
        ];
    }
}
