<?php

namespace CaturSeptian\PennantAnalytics\Statistics;

use InvalidArgumentException;

/**
 * Two-proportion z-test (pooled), the textbook test for A/B conversion rates.
 *
 * Each subject is a Bernoulli trial (converted or not), so for groups A and B:
 *
 *   p_A = x_A / n_A        p_B = x_B / n_B
 *   p   = (x_A + x_B) / (n_A + n_B)                     pooled rate under H0
 *   SE  = sqrt( p * (1 - p) * (1/n_A + 1/n_B) )
 *   z   = (p_B - p_A) / SE
 *   p-value = 2 * (1 - Phi(|z|))                        two-tailed
 *
 * For a 2x2 table this is mathematically identical to Pearson's chi-square
 * test without continuity correction (chi^2 = z^2), so one test covers both.
 *
 * The normal approximation is only trusted when every cell has at least 5
 * expected successes and failures and each group meets a minimum sample size.
 */
final class TwoProportionZTest
{
    public function __construct(
        private float $confidence = 0.95,
        private int $minSampleSize = 100,
    ) {
        if ($confidence <= 0 || $confidence >= 1) {
            throw new InvalidArgumentException('Confidence must be between 0 and 1 (exclusive).');
        }
    }

    public function compare(int $controlConversions, int $controlSize, int $variantConversions, int $variantSize): ZTestResult
    {
        if ($controlConversions > $controlSize || $variantConversions > $variantSize || min($controlConversions, $controlSize, $variantConversions, $variantSize) < 0) {
            throw new InvalidArgumentException('Conversions must be between 0 and the sample size.');
        }

        $controlRate = $controlSize > 0 ? $controlConversions / $controlSize : 0.0;
        $variantRate = $variantSize > 0 ? $variantConversions / $variantSize : 0.0;

        $z = 0.0;
        $pValue = 1.0;

        if ($controlSize > 0 && $variantSize > 0) {
            $pooled = ($controlConversions + $variantConversions) / ($controlSize + $variantSize);
            $standardError = sqrt($pooled * (1 - $pooled) * (1 / $controlSize + 1 / $variantSize));

            if ($standardError > 0) {
                $z = ($variantRate - $controlRate) / $standardError;
                $pValue = NormalDistribution::twoTailedPValue($z);
            }
        }

        return new ZTestResult(
            controlRate: $controlRate,
            variantRate: $variantRate,
            relativeLift: $controlRate > 0 ? ($variantRate - $controlRate) / $controlRate * 100 : null,
            absoluteDifference: ($variantRate - $controlRate) * 100,
            zScore: $z,
            pValue: $pValue,
            confidence: $this->confidence,
            hasEnoughData: $this->hasEnoughData($controlConversions, $controlSize, $variantConversions, $variantSize),
        );
    }

    private function hasEnoughData(int $controlConversions, int $controlSize, int $variantConversions, int $variantSize): bool
    {
        if (min($controlSize, $variantSize) < $this->minSampleSize) {
            return false;
        }

        $pooled = ($controlConversions + $variantConversions) / ($controlSize + $variantSize);

        foreach ([$controlSize, $variantSize] as $n) {
            if ($n * $pooled < 5 || $n * (1 - $pooled) < 5) {
                return false;
            }
        }

        return true;
    }
}
