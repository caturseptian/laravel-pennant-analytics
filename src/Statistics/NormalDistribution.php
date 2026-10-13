<?php

namespace CaturSeptian\PennantAnalytics\Statistics;

/**
 * Standard normal distribution helpers.
 *
 * PHP has no native erf(), so we use the complementary error function
 * approximation from Numerical Recipes (erfcc, Chebyshev fit) which has a
 * fractional error below 1.2e-7 everywhere - far more precision than an A/B
 * test verdict needs, with no extensions required.
 */
final class NormalDistribution
{
    /**
     * Cumulative distribution function Phi(z) = P(Z <= z).
     */
    public static function cdf(float $z): float
    {
        return 0.5 * self::erfc(-$z / M_SQRT2);
    }

    /**
     * Two-tailed p-value for a z statistic: P(|Z| >= |z|).
     */
    public static function twoTailedPValue(float $z): float
    {
        return min(1.0, self::erfc(abs($z) / M_SQRT2));
    }

    /**
     * Critical |z| for a two-tailed test at the given confidence, found by
     * bisection on the CDF (monotonic, so 60 iterations is overkill-exact).
     */
    public static function criticalValue(float $confidence): float
    {
        $target = 1 - (1 - $confidence) / 2;
        [$low, $high] = [0.0, 10.0];

        for ($i = 0; $i < 60; $i++) {
            $mid = ($low + $high) / 2;
            self::cdf($mid) < $target ? $low = $mid : $high = $mid;
        }

        return ($low + $high) / 2;
    }

    public static function erfc(float $x): float
    {
        $z = abs($x);
        $t = 1 / (1 + 0.5 * $z);

        $r = $t * exp(-$z * $z - 1.26551223 + $t * (1.00002368 + $t * (0.37409196 + $t * (0.09678418
            + $t * (-0.18628806 + $t * (0.27886807 + $t * (-1.13520398 + $t * (1.48851587
            + $t * (-0.82215223 + $t * 0.17087277)))))))));

        return $x >= 0 ? $r : 2 - $r;
    }
}
