<?php

namespace CaturSeptian\PennantAnalytics\Analytics;

use CaturSeptian\PennantAnalytics\Statistics\TwoProportionZTest;
use Illuminate\Support\Collection;

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
        public float $confidence,
    ) {}

    /**
     * @param  Collection<int, object{variant: string, action: string, total: int, uniques: int}>  $rows
     * @param  array{confidence?: float, min_sample_size?: int, control_variants?: list<string>}  $options
     */
    public static function build(string $feature, string $action, Collection $rows, string $impressionAction, array $options = []): self
    {
        $confidence = (float) ($options['confidence'] ?? 0.95);
        $impressions = $rows->where('action', $impressionAction)->keyBy('variant');
        $conversions = $rows->where('action', $action)->keyBy('variant');

        $labels = $impressions->keys()->merge($conversions->keys())->unique()->sort()->values();
        $control = self::pickControl($labels, $impressions, $options['control_variants'] ?? []);

        $comparisons = max(1, $labels->count() - 1);
        $test = new TwoProportionZTest(
            confidence: 1 - (1 - $confidence) / $comparisons,
            minSampleSize: (int) ($options['min_sample_size'] ?? 100),
        );

        $controlImpressions = (int) ($impressions[$control]->uniques ?? 0);
        $controlConversions = min($controlImpressions, (int) ($conversions[$control]->uniques ?? 0));

        $variants = $labels->map(function (string $label) use ($impressions, $conversions, $control, $test, $controlImpressions, $controlConversions) {
            $n = (int) ($impressions[$label]->uniques ?? 0);
            // Clamp guards against legacy data written outside the engine.
            $x = min($n, (int) ($conversions[$label]->uniques ?? 0));

            return new VariantResult(
                variant: $label,
                impressions: $n,
                conversions: $x,
                totalConversions: (int) ($conversions[$label]->total ?? 0),
                isControl: $label === $control,
                comparison: $label === $control ? null : $test->compare($controlConversions, $controlImpressions, $x, $n),
            );
        })
            // Control first, then best performers.
            ->sortBy([fn (VariantResult $a, VariantResult $b) => [$b->isControl, $b->conversionRate()] <=> [$a->isControl, $a->conversionRate()]])
            ->values()
            ->all();

        return new self($feature, $action, $variants, $confidence);
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
        return (bool) $this->leader()?->comparison?->isSignificant() && $this->leader()->comparison->isWinning();
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
     * @param  Collection<int, string>  $labels
     * @param  Collection<string, object>  $impressions
     * @param  list<string>  $preferred
     */
    private static function pickControl(Collection $labels, Collection $impressions, array $preferred): ?string
    {
        foreach ($preferred as $candidate) {
            if ($labels->contains($candidate)) {
                return $candidate;
            }
        }

        return $labels->sortByDesc(fn (string $label) => $impressions[$label]->uniques ?? 0)->first();
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
            ], $this->variants),
        ];
    }
}
